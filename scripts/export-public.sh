#!/usr/bin/env bash
# Export the committed tree (HEAD) as a clean public copy and check it, failing closed.
#
# Usage:
#   scripts/export-public.sh <output-dir>       export HEAD into <output-dir>, then check it
#   scripts/export-public.sh --scan <dir>       check an existing directory only
#   scripts/export-public.sh --selftest         plant every known bypass and confirm each is caught
#   scripts/export-public.sh --update-allowlist-lock
#                                               after a human review of the allowlist's exact-line: and
#                                               password-value: entries, record them in the review lock
#
# Options (before the other arguments):
#   --denylist <file>    private denylist (default: the only *-denylist.txt next to this script)
#   --allowlist <file>   public allowlist (default: public-allowlist.txt next to this script)
#
# What "clean" means (anything else is a finding and the run fails):
#   - every file matches a path glob AND an extension in the allowlist; only the allowlist's
#     binary: paths may be binary
#   - no symlinks, gitlinks/submodules, special files, empty directories or VCS metadata
#   - every file is UTF-8 text: no NUL bytes, UTF-16, other encodings, compressed data, or
#     invisible characters (zero-width, soft hyphen, bidi controls, BOM after the first byte)
#   - no denylist or built-in rule matches the file names or the content, checked on the raw text
#     and again after NFKC, folding Cyrillic/Greek look-alikes to Latin, percent-decoding, HTML-entity
#     decoding, removing invisible characters, decoding JSON/PHP (\uXXXX, \xXX, octal) and CSS (\hex)
#     escapes, joining string concatenations and lines, and decoding base64 fragments
#   - no passwords (any assignment form, env() defaults, URL user:password@), long hex/base64 values after
#     secret/key/token/password/auth/credential words, global IPv4 or IPv6 addresses
#   - .env.example holds only empty or allowlisted placeholder values, also in KEY=value text inside comments
#   - the allowlist's exact-line: and password-value: entries match the review lock
#     (public-allowlist.lock next to the allowlist); any change needs a human review first
#
# Exit codes: 0 = clean, 1 = something found (printed as "path:line [rule] (view)" or "path [reason]",
# never the matched content), 2 = usage or setup error. Requires python3 (standard library only).
#
# Read-only towards git: the only git commands used on the repository are ls-tree, archive and
# rev-parse. Nothing is committed or pushed. --update-allowlist-lock writes only the lock file.
set -euo pipefail
export LC_ALL=C.UTF-8

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
DENYLIST=""
ALLOWLIST="$SCRIPT_DIR/public-allowlist.txt"

die() { echo "export-public: $*" >&2; exit 2; }

usage() {
    sed -n '2,37p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 2
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --denylist) [[ $# -ge 2 ]] || die "--denylist needs a file"; DENYLIST="$2"; shift 2 ;;
        --allowlist) [[ $# -ge 2 ]] || die "--allowlist needs a file"; ALLOWLIST="$2"; shift 2 ;;
        -h|--help) usage ;;
        *) break ;;
    esac
done
[[ $# -ge 1 ]] || usage

command -v python3 >/dev/null 2>&1 || die "python3 is required"
if [[ -z "$DENYLIST" ]]; then
    shopt -s nullglob
    candidates=("$SCRIPT_DIR"/*-denylist.txt)
    shopt -u nullglob
    (( ${#candidates[@]} == 1 )) || die "expected exactly one *-denylist.txt in $SCRIPT_DIR (found ${#candidates[@]}); pass --denylist <file>"
    DENYLIST="${candidates[0]}"
fi
[[ -f "$DENYLIST" ]] || die "denylist not found: $DENYLIST"
[[ -f "$ALLOWLIST" ]] || die "allowlist not found: $ALLOWLIST"

# ---------------------------------------------------------------------------------------------
# The checker (Python 3 standard library only)
# ---------------------------------------------------------------------------------------------
IFS= read -r -d '' CHECKER <<'PYCODE' || true
import base64, binascii, bisect, bz2, gzip, hashlib, html, io, ipaddress, lzma, os, re, shutil, stat
import subprocess, sys, unicodedata, zipfile, zlib
from urllib.parse import unquote

MAX_BYTES = 1024 * 1024
MAX_INFLATE = 4 * 1024 * 1024


def die(msg):
    print('export-public: ' + msg, file=sys.stderr)
    sys.exit(2)


# Zero-width characters, soft hyphen, word joiners and bidi controls. U+FEFF is allowed only as the
# first character of a file (or where the allowlist has an invisible: entry for that file).
INVISIBLE = ('\u00ad\u061c\u200b\u200c\u200d\u200e\u200f\u202a\u202b\u202c\u202d\u202e'
             '\u2060\u2061\u2062\u2063\u2064\u2066\u2067\u2068\u2069\ufeff')
INVISIBLE_RE = re.compile('[' + INVISIBLE + ']')

# Fake share tokens used by the tests: the only xmt values of 12+ characters that may be published.
FAKE_XMT = frozenset(['FAKE', 'FAKE1', 'FAKEXMTABC', 'FAKEXMTAQF', 'FAKEXMTZZZ',
                      'FAKEXMTVALUE01', 'FAKEXMTVALUE02', 'FAKEXMTVALUE03'])

# The only e-mail domains that may be published.
EMAIL_OK_RE = re.compile(r'example\.(?:com|net|org|test)|(?:[a-z0-9-]+\.)+example|threads\.(?:com|net)', re.I)

# Values after "password" that are obviously placeholders.
PASSWORD_PLACEHOLDERS = frozenset(['password', 'passwd', 'secret', 'change-me', 'changeme',
                                   'example', 'placeholder', 'null', 'your-password', 'pass', 'pw'])
# Variable references that stand in for a password ($ENV, ${ENV}, %env(ENV)%, %param%, {{ x }}, <x>).
PASSWORD_REF_RE = re.compile(r'\$\{?[A-Z_][A-Z0-9_]*\}?|\$\{[A-Za-z_][A-Za-z0-9_.:\-]*\}'
                             r'|%(?:env\()?[A-Za-z0-9_.:]+\)?%|\{\{.*\}\}|<[^<>]*>')
# Words that name a secret; a long random-looking value assigned after one is a finding.
SECRET_WORD = r'(?:secret|key|token|password|passwd|pwd|auth|credential)'
# IPv6 networks that may always be published (loopback, documentation, link-local, unique-local);
# any other global address is a finding.
IPV6_GLOBAL_UNICAST = ipaddress.IPv6Network((0x2000 << 112, 3))  # global unicast space
IPV6_OK_NETS = [ipaddress.IPv6Network(n) for n in ('::1/128', '2001:db8::/32', 'fe80::/10', 'fc00::/7')]

# Common look-alike letters (Cyrillic and Greek) folded to the Latin letter they imitate.
HOMOGLYPHS = str.maketrans({
    '\u0430': 'a', '\u0432': 'b', '\u0441': 'c', '\u0501': 'd', '\u0435': 'e', '\u0451': 'e',
    '\u0261': 'g', '\u04bb': 'h', '\u043d': 'h', '\u0456': 'i', '\u0457': 'i', '\u0458': 'j', '\u043a': 'k',
    '\u04cf': 'l', '\u043c': 'm', '\u043f': 'n', '\u043e': 'o', '\u0440': 'p', '\u051b': 'q', '\u0433': 'r',
    '\u0455': 's', '\u0442': 't', '\u0446': 'u', '\u0475': 'v', '\u051d': 'w', '\u0445': 'x', '\u0443': 'y',
    '\u0410': 'A', '\u0412': 'B', '\u0421': 'C', '\u0415': 'E', '\u041d': 'H', '\u0406': 'I', '\u0408': 'J',
    '\u041a': 'K', '\u041c': 'M', '\u041e': 'O', '\u0420': 'P', '\u0405': 'S', '\u0422': 'T', '\u0425': 'X',
    '\u0423': 'Y', '\u04c0': 'l', '\u0417': '3', '\u0431': '6',
    '\u03b1': 'a', '\u03b2': 'b', '\u03b5': 'e', '\u03b7': 'n', '\u03b9': 'i', '\u03ba': 'k', '\u03bd': 'v',
    '\u03bf': 'o', '\u03c1': 'p', '\u03c4': 't', '\u03c5': 'u', '\u03c7': 'x', '\u03c9': 'w', '\u03f2': 'c',
    '\u0391': 'A', '\u0392': 'B', '\u0395': 'E', '\u0396': 'Z', '\u0397': 'H', '\u0399': 'I', '\u039a': 'K',
    '\u039c': 'M', '\u039d': 'N', '\u039f': 'O', '\u03a1': 'P', '\u03a4': 'T', '\u03a5': 'Y', '\u03a7': 'X',
})
# JSON/PHP/JS string escapes (\uXXXX, \u{X}, \xXX, octal) and CSS escapes (\hex plus one optional space).
C_ESCAPE_RE = re.compile(r'\\(?:u\{([0-9A-Fa-f]{1,6})\}|u([0-9A-Fa-f]{4})|x([0-9A-Fa-f]{1,2})|([0-7]{1,3}))')
CSS_ESCAPE_RE = re.compile(r'\\([0-9A-Fa-f]{1,6})[ \t]?')

FORBIDDEN_NAME_RE = re.compile(
    r'(?:^|[._-])(?:cookies?|id_(?:rsa|dsa|ecdsa|ed25519)|credentials?|secrets?|htpasswd|netrc|pgpass)(?:[._-]|$)'
    r'|\.(?:pem|key|p12|pfx|kdbx|sqlite3?|db|sql|har|log|bak|swp|orig|gz|tgz|zip|tar|7z|rar|bz2|xz)$', re.I)

COMPRESSED_MAGIC = [(b'\x1f\x8b', 'gzip'), (b'PK\x03\x04', 'zip'), (b'PK\x05\x06', 'zip'),
                    (b'\xfd7zXZ\x00', 'xz'), (b'7z\xbc\xaf\x27\x1c', '7z'), (b'\x28\xb5\x2f\xfd', 'zstd'),
                    (b'Rar!\x1a\x07', 'rar'), (b'\x04\x22\x4d\x18', 'lz4'), (b'SQLite format 3', 'sqlite')]

CONCAT_RE = re.compile(r'["\'`]\s*(?:\.\.|\|\||[.+&])?\s*["\'`]')
# Join a line break only next to a separator (or after a backslash), where a split token would be.
LINEJOIN_RE = re.compile(r'\\\r?\n[ \t]*|(?<=[.@:/_\-])[ \t]*\r?\n[ \t]*|[ \t]*\r?\n[ \t]*(?=[.@:/_\-])')
B64_RE = re.compile(r'[A-Za-z0-9+/_\-]{16,}={0,2}')
ENV_LINE_RE = re.compile(r'([A-Za-z_][A-Za-z0-9_]*)=(.*)')
ENV_INLINE_RE = re.compile(r'(?<![A-Za-z0-9_\-])([A-Za-z_][A-Za-z0-9_]*)=("[^"]*"|\'[^\']*\'|[^\s`]*)')
ENV_GENERIC_OK_RE = re.compile(r'[0-9]{1,6}|true|false|null|\$\{[A-Za-z_][A-Za-z0-9_]*\}')
# Rule categories an allowlist exact-line: entry may exempt (nothing else can be exempted that way).
EXACT_LINE_CATEGORIES = frozenset(['name', 'facebook'])
CATEGORIES = ('default', 'name', 'email', 'facebook')
POSIX_CLASSES = {'[:space:]': r'\s', '[:digit:]': '0-9', '[:alpha:]': 'A-Za-z',
                 '[:alnum:]': 'A-Za-z0-9', '[:upper:]': 'A-Z', '[:lower:]': 'a-z'}


# ---------------------------------------------------------------------------------------------
# Rules
# ---------------------------------------------------------------------------------------------
class Rule:
    """A regex plus an optional validator. cat: default | name (owner names) | email."""

    def __init__(self, label, pattern, flags, cat='default', check=None):
        self.label = label
        self.re = re.compile(pattern, flags | re.M)
        self.cat = cat
        self.check = check

    def hits(self, text):
        for m in self.re.finditer(text):
            if self.check is None or self.check(m):
                yield m.start()


def email_check(m):
    return not EMAIL_OK_RE.fullmatch(m.group(1))


def xmt_check(m):
    return m.group(1) not in FAKE_XMT


def builtin_rules(cfg):
    def ipv4_check(m):
        if m.group(1) in cfg.ip_values:
            return False
        try:
            return ipaddress.IPv4Address(m.group(1)).is_global
        except ValueError:
            return False

    def is_fake_password(value):
        if value[-1:] in ('.', ',', ';') and is_fake_password(value[:-1]):
            return True  # a placeholder at the end of a sentence
        if value in cfg.password_values or value.lower() in PASSWORD_PLACEHOLDERS or value.strip('*x.') == '':
            return True
        if re.fullmatch(r'[A-Z][A-Z0-9_]*', value):
            return True  # the name of another setting, not a value
        return PASSWORD_REF_RE.fullmatch(value) is not None

    def password_check(m):
        return not is_fake_password(next(g for g in m.groups() if g is not None))

    def userinfo_check(m):
        return not is_fake_password(m.group(2))

    def entropy_check(m):
        value = m.group(1)
        if value in cfg.password_values:
            return False
        if re.fullmatch(r'[0-9A-Fa-f]{32,}', value):
            return True
        return re.search(r'[0-9]', value) is not None and re.search(r'[A-Za-z]', value) is not None

    def ipv6_check(m):
        cand = m.group(1)
        if cand in cfg.ip_values or not re.search(r'[0-9]', cand):
            return False
        try:
            addr = ipaddress.IPv6Address(cand.split('%', 1)[0])
        except ValueError:
            return False
        if addr.ipv4_mapped is not None:
            return str(addr.ipv4_mapped) not in cfg.ip_values and addr.ipv4_mapped.is_global
        # Global unicast space only: this also keeps code such as data[1::2] out.
        return addr in IPV6_GLOBAL_UNICAST and not any(addr in n for n in IPV6_OK_NETS)

    rules = [
        Rule('builtin:email', r'[A-Za-z0-9._%+\-]+@((?:[A-Za-z0-9\-]+\.)+[A-Za-z]{2,})(?![A-Za-z0-9\-])',
             re.I, 'email', email_check),
        Rule('builtin:xmt', r'xmt(?:[\s"\'`=:>]|%3[dD]|%22|%3[aA]){1,10}([A-Za-z0-9_\-+/%]{12,})',
             re.I, 'default', xmt_check),
        Rule('builtin:app-key', r'APP_KEY\s*=\s*["\']?base64:[A-Za-z0-9+/]{16,}', re.I),
        Rule('builtin:laravel-key', r'base64:[A-Za-z0-9+/]{40,}={0,2}', 0),
        Rule('builtin:bot-token', r'(?<![0-9])[0-9]{8,10}:[A-Za-z0-9_\-]{30,}', 0),
        Rule('builtin:sessionid', r'sessionid\s*=\s*["\x27]?[A-Za-z0-9%_-]{8,}', re.I),
        Rule('builtin:password',
             r'pass(?:word|wd)[A-Za-z0-9_]*["\']?\s*(?:=>|:|=)\s*'
             r'(?:"([!#-~]{6,})"|\'([!-&(-~]{6,})\'|([!#-&*-+\--:<-~]{6,})[ \t]*$)',
             re.I, 'default', password_check),
        # putenv('X_PASSWORD=value'), "--password=value" inside a string
        Rule('builtin:password', r'pass(?:word|wd)[A-Za-z0-9_]*=([!#-&*-+\--:<-\[\]-~]{6,})(?=["\'])',
             re.I, 'default', password_check),
        # $_ENV['X_PASSWORD'] = 'value', $config["password"] = "value"
        Rule('builtin:password',
             r'pass(?:word|wd)[A-Za-z0-9_]*["\']?\]\s*=(?!=)\s*(?:"([!#-~]{6,})"|\'([!-&(-~]{6,})\')',
             re.I, 'default', password_check),
        # env('X_PASSWORD', '...'), getenv(...), config(...): a key that ends in password
        Rule('builtin:password',
             r'(?:env|getenv|config)\(\s*["\'][A-Za-z0-9_.]*pass(?:word|wd)["\']\s*,\s*'
             r'(?:"([!#-~]{6,})"|\'([!-&(-~]{6,})\')',
             re.I, 'default', password_check),
        # scheme://user:password@host (any host); passwords shorter than 4 characters are ignored
        Rule('builtin:password', r'(?<![A-Za-z0-9+.\-])[A-Za-z][A-Za-z0-9+.\-]*://([^\s:/@?#"\'`<>]+):'
             r'([^\s/@?#"\'`<>]{4,})@', 0, 'default', userinfo_check),
        Rule('builtin:high-entropy-secret',
             SECRET_WORD + r'[A-Za-z0-9_\-]*["\'\]]{0,3}\s*(?:=>|:|=)\s*["\'`]?([A-Za-z0-9+/_\-]{32,}={0,2})'
             r'(?![A-Za-z0-9+/_\-])', re.I, 'default', entropy_check),
        Rule('builtin:ipv6',
             r'(?<![0-9A-Za-z:.])((?:[0-9A-Fa-f]{0,4}:){2,7}(?:[0-9A-Fa-f]{0,4}|(?:[0-9]{1,3}\.){3}[0-9]{1,3})'
             r'(?:%[0-9A-Za-z]+)?)(?![0-9A-Za-z:.])',
             0, 'default', ipv6_check),
        Rule('builtin:ipv4', r'(?<![0-9.])((?:[0-9]{1,3}\.){3}[0-9]{1,3})(?![0-9]|\.[0-9])', 0,
             'default', ipv4_check),
        Rule('builtin:facebook-url', r'facebook\.com/[A-Za-z0-9._\-]+', re.I, 'facebook'),
        Rule('builtin:private-key', r'-----BEGIN [A-Z ]*PRIVATE KEY-----', 0),
        Rule('builtin:aws-key', r'\bAKIA[0-9A-Z]{16}\b', 0),
        Rule('builtin:github-token', r'\bgh[pousr]_[A-Za-z0-9]{36}\b', 0),
        Rule('builtin:slack-token', r'\bxox[abposr]-[A-Za-z0-9\-]{10,}', 0),
    ]
    return rules


def owner_rule(name, label):
    words = [re.escape(w) for w in name.split()]
    if not words:
        return None
    sep = r'[\s._\-]*'
    alts = [sep.join(words)]
    if len(words) > 1:
        alts.append(sep.join(reversed(words)))
    return Rule(label, '(?:' + '|'.join(alts) + ')', re.I, 'name')


def ere_to_py(pattern):
    for k, v in POSIX_CLASSES.items():
        pattern = pattern.replace(k, v)
    return pattern


def glob_to_re(glob):
    out, i = '', 0
    while i < len(glob):
        if glob.startswith('**/', i):
            out += '(?:.*/)?'; i += 3
        elif glob.startswith('**', i):
            out += '.*'; i += 2
        elif glob[i] == '*':
            out += '[^/]*'; i += 1
        elif glob[i] == '?':
            out += '[^/]'; i += 1
        else:
            out += re.escape(glob[i]); i += 1
    return re.compile(out)


class Config:
    def __init__(self, denylist, allowlist, repo=None):
        self.rules, self.samples, self.keyed, self.owners, self.privates = [], [], {}, [], []
        self.globs, self.exts, self.noext, self.binary, self.env_values = [], set(), set(), set(), set()
        self.invisible_ok, self.password_values, self.ip_values = set(), set(), set()
        self.excludes, self.exact_lines = [], []   # exact_lines: (path, frozenset(categories), line)
        self._load_denylist(denylist)
        if repo:
            try:
                name = subprocess.run(['git', '-C', repo, 'config', 'user.name'], capture_output=True,
                                      text=True).stdout.strip()
            except OSError:
                name = ''
            # A placeholder user.name such as 't' would match everywhere; the denylist owner: line still applies.
            if len(name) >= 4 and name.lower() not in (o.lower() for o in self.owners):
                self.owners.append(name)
                self.rules.append(owner_rule(name, 'owner-name (git user.name)'))
        self.rules.extend(builtin_rules(self))
        if not self.owners:
            die('no owner name known: add an owner:<Name> line to the denylist')
        self._load_allowlist(allowlist)

    def _load_denylist(self, path):
        with open(path, encoding='utf-8') as fh:
            lines = fh.read().split('\n')
        for n, line in enumerate(lines, 1):
            line = line.rstrip('\r')
            m = re.match(r'^#\s*selftest:\s*(.+)$', line)
            if m:
                sample = m.group(1)
                self.samples.append(sample)
                k = re.match(r'^(host|fb):\s*(.+)$', sample)
                if k:
                    self.keyed[k.group(1)] = k.group(2).strip()
                continue
            if not line.strip() or line.startswith('#'):
                continue
            label = 'denylist line %d' % n
            try:
                if line.startswith('owner:'):
                    name = line[len('owner:'):].strip()
                    self.owners.append(name)
                    self.rules.append(owner_rule(name, label))
                elif line.startswith('name:'):
                    self.rules.append(owner_rule(line[len('name:'):].strip(), label))
                elif line.startswith('private:'):
                    name = line[len('private:'):].strip()
                    self.privates.append(name)
                    self.rules.append(Rule(label, r'(?<![A-Za-z0-9])' + re.escape(name) + r'(?![A-Za-z0-9])', re.I))
                elif line.startswith('cs:'):
                    self.rules.append(Rule(label, ere_to_py(line[3:]), 0))
                else:
                    self.rules.append(Rule(label, ere_to_py(line), re.I))
            except re.error as exc:
                die('bad regex on %s: %s' % (label, exc))
        if not self.rules:
            die('denylist has no rules: ' + path)

    def _load_allowlist(self, path):
        with open(path, encoding='utf-8') as fh:
            for line in fh.read().split('\n'):
                line = line.rstrip('\r')
                if not line.strip() or line.startswith('#'):
                    continue
                if line.startswith('ext:'):
                    self.exts.add(line[4:].strip())
                elif line.startswith('noext:'):
                    self.noext.add(line[6:].strip())
                elif line.startswith('binary:'):
                    self.binary.add(line[7:].strip())
                elif line.startswith('env-value:'):
                    self.env_values.add(line[10:])
                elif line.startswith('invisible:'):
                    path, _, cp = line[10:].strip().rpartition(' ')
                    self.invisible_ok.add((path, cp.upper()))
                elif line.startswith('password-value:'):
                    self.password_values.add(line[15:])
                elif line.startswith('ip-value:'):
                    self.ip_values.add(line[9:].strip())
                elif line.startswith('exclude:'):
                    self.excludes.append(glob_to_re(line[8:].strip()))
                elif line.startswith('exact-line:'):
                    parts = line[11:].split('|', 2)
                    cats = frozenset(c.strip() for c in parts[1].split(',')) if len(parts) == 3 else frozenset()
                    if len(parts) != 3 or not cats <= EXACT_LINE_CATEGORIES:
                        die('bad exact-line entry in allowlist (want <path>|name,facebook|<line>)')
                    self.exact_lines.append((parts[0].strip(), cats, parts[2]))
                else:
                    self.globs.append(glob_to_re(line.strip()))
        if not self.globs or not self.exts:
            die('allowlist has no path globs or no extensions: ' + path)


# ---------------------------------------------------------------------------------------------
# Review lock: every exact-line: and password-value: entry, as the first 12 hex digits of its sha256
# ---------------------------------------------------------------------------------------------
LOCK_KINDS = ('exact-line:', 'password-value:')
LOCK_HEADER = ('# Review lock for public-allowlist.txt, written by scripts/export-public.sh --update-allowlist-lock.\n'
               '# One line per exact-line: and password-value: entry of the allowlist: <kind> <first 12 hex digits\n'
               '# of the sha256 of the whole entry line>. These entries exempt text from the checks, so the\n'
               '# export fails while this list and the allowlist disagree: review every changed entry by hand,\n'
               '# then run scripts/export-public.sh --update-allowlist-lock.\n')


def lock_path_for(allowlist):
    return os.path.splitext(allowlist)[0] + '.lock'


def allowlist_lock_entries(allowlist):
    with open(allowlist, encoding='utf-8') as fh:
        lines = [l.rstrip('\r') for l in fh.read().split('\n')]
    out = []
    for l in lines:
        for kind in LOCK_KINDS:
            if l.startswith(kind):
                out.append('%s %s' % (kind[:-1], hashlib.sha256(l.encode('utf-8')).hexdigest()[:12]))
    return sorted(out)


def read_lock(lock):
    if not os.path.isfile(lock):
        return None
    with open(lock, encoding='utf-8') as fh:
        return sorted(l.strip() for l in fh.read().split('\n') if l.strip() and not l.startswith('#'))


def write_lock(allowlist):
    lock = lock_path_for(allowlist)
    entries = allowlist_lock_entries(allowlist)
    with open(lock, 'w', encoding='utf-8') as fh:
        fh.write(LOCK_HEADER + ''.join(e + '\n' for e in entries))
    return lock, entries


def check_lock(allowlist, sc, rel=None, quiet=False):
    """Add a finding when the allowlist's exempting entries differ from the review lock."""
    lock = lock_path_for(allowlist)
    current, locked = allowlist_lock_entries(allowlist), read_lock(lock)
    if locked is None:
        note = 'no review lock file %s' % os.path.basename(lock)
    elif current == locked:
        return True
    else:
        added = [e for e in current if e not in locked]
        removed = [e for e in locked if e not in current]
        note = 'unreviewed: %s' % ', '.join(['+' + e for e in added] + ['-' + e for e in removed])
    sc.add(rel or os.path.basename(allowlist), 'allowlist-lock-mismatch', note=note)
    if not quiet:
        print('export-public: 白名單有異動，請人工審查後執行 --update-allowlist-lock '
          '(the exact-line:/password-value: entries changed; review them by hand, then run '
          'scripts/export-public.sh --update-allowlist-lock)', file=sys.stderr)
    return False


# ---------------------------------------------------------------------------------------------
# Views: the same text, decoded and normalised in different ways
# ---------------------------------------------------------------------------------------------
def normalize(s):
    for _ in range(4):
        n = unicodedata.normalize('NFKC', html.unescape(unquote(s, errors='replace'))).translate(HOMOGLYPHS)
        n = INVISIBLE_RE.sub('', n)
        if n == s:
            break
        s = n
    return s


def _codepoint(cp):
    if cp > 0x10FFFF or 0xD800 <= cp <= 0xDFFF:
        return '\ufffd'
    c = chr(cp)
    return ' ' if c in '\r\n' else c


def c_unescape(s):
    """Decode \\uXXXX, \\u{X}, \\xXX and octal escapes (JSON, PHP, JS, C)."""
    def rep(m):
        h = m.group(1) or m.group(2) or m.group(3)
        return _codepoint(int(h, 16) if h else int(m.group(4), 8))
    return C_ESCAPE_RE.sub(rep, s)


def css_unescape(s):
    """Decode CSS escapes: a backslash, 1-6 hex digits and one optional space."""
    return CSS_ESCAPE_RE.sub(lambda m: _codepoint(int(m.group(1), 16)), s)


def line_mapper(text):
    nl = [m.start() for m in re.finditer('\n', text)]
    return lambda pos: bisect.bisect_left(nl, pos) + 1


def squeeze(text, rx):
    """Remove every match of rx; return the new text and a map new position -> old position."""
    pieces, starts, origins, last, o = [], [], [], 0, 0
    for m in rx.finditer(text):
        pieces.append(text[last:m.start()]); starts.append(o); origins.append(last)
        o += m.start() - last; last = m.end()
    pieces.append(text[last:]); starts.append(o); origins.append(last)

    def to_old(pos):
        k = bisect.bisect_right(starts, pos) - 1
        return origins[k] + (pos - starts[k])
    return ''.join(pieces), to_old


def make_views(text):
    raw_line = line_mapper(text)
    lines = text.split('\n')
    views = [('raw', text, raw_line)]
    bases = [('', lambda l: l)]
    if '\\' in text:
        bases += [('unescaped/', c_unescape), ('css-unescaped/', css_unescape)]
    for prefix, decode in bases:
        # Every transformation works line by line, so line numbers stay those of the original text.
        norm = '\n'.join(normalize(decode(l)).replace('\r', ' ').replace('\n', ' ') for l in lines)
        norm_line = line_mapper(norm)
        concat, c_old = squeeze(norm, CONCAT_RE)
        joined, j_old = squeeze(concat, LINEJOIN_RE)
        views += [(prefix + 'normalized', norm, norm_line),
                  (prefix + 'concat', concat, lambda p, nl=norm_line, co=c_old: nl(co(p))),
                  (prefix + 'joined', joined, lambda p, nl=norm_line, co=c_old, jo=j_old: nl(co(jo(p))))]
    return views


def base64_fragments(text, depth=2):
    found = []
    for m in B64_RE.finditer(text):
        s = m.group(0).rstrip('=').replace('-', '+').replace('_', '/')
        s += '=' * (-len(s) % 4)
        try:
            decoded = base64.b64decode(s, validate=True).decode('utf-8')
        except (binascii.Error, ValueError, UnicodeDecodeError):
            continue
        printable = sum(1 for c in decoded if c.isprintable() or c in '\t\r\n')
        if not decoded or printable < 0.85 * len(decoded):
            continue
        found.append((m.start(), decoded))
        if depth > 1:
            found.extend((m.start(), d) for _, d in base64_fragments(decoded, depth - 1))
    return found


# ---------------------------------------------------------------------------------------------
# Scanner
# ---------------------------------------------------------------------------------------------
class Scanner:
    def __init__(self, cfg, quiet=False):
        self.cfg = cfg
        self.quiet = quiet
        self.findings = []
        self._seen = set()

    def add(self, rel, code, line=None, note=None):
        key = (rel, line, code)
        if key in self._seen:
            return
        self._seen.add(key)
        self.findings.append({'rel': rel, 'line': line, 'code': code, 'note': note})

    def report(self):
        for f in self.findings:
            where = f['rel'] + (':%d' % f['line'] if f['line'] else '')
            print('%s [%s]%s' % (where, f['code'], (' (%s)' % f['note']) if f['note'] else ''))

    # --- names -------------------------------------------------------------------------------
    def check_path_name(self, rel):
        for view in (rel, normalize(rel)):
            for r in self.cfg.rules:
                if r.cat == 'email':
                    continue
                if next(r.hits(view), None) is not None:
                    self.add(rel, 'path-name', note=r.label)

    def check_policy(self, rel, name):
        cfg = self.cfg
        if any(g.fullmatch(rel) for g in cfg.excludes):
            self.add(rel, 'excluded-path')
        elif not any(g.fullmatch(rel) for g in cfg.globs):
            self.add(rel, 'not-allowlisted')
        if name.startswith('.') and name.count('.') == 1:
            ext = name[1:]
        elif '.' in name.strip('.'):
            ext = name.rsplit('.', 1)[1]
        else:
            ext = None
        if ext is None:
            if name not in cfg.noext:
                self.add(rel, 'extension-not-allowed', note='no extension')
        elif ext not in cfg.exts:
            self.add(rel, 'extension-not-allowed', note='.' + ext)
        if FORBIDDEN_NAME_RE.search(name):
            self.add(rel, 'forbidden-name')
        if name.startswith('.env') and name != '.env.example':
            self.add(rel, 'env-file')

    # --- tree --------------------------------------------------------------------------------
    def scan_tree(self, root):
        if not os.path.isdir(root) or os.path.islink(root):
            die('not a directory: ' + root)
        self._walk(os.path.abspath(root), '')
        # A published allowlist must agree with the published review lock next to it.
        allow = os.path.join(root, 'scripts', 'public-allowlist.txt')
        if os.path.isfile(allow) and not os.path.islink(allow):
            check_lock(allow, self, 'scripts/public-allowlist.txt', quiet=self.quiet)
        return self.findings

    def _walk(self, root, rel_dir):
        full = os.path.join(root, rel_dir) if rel_dir else root
        names = sorted(os.listdir(full))
        if rel_dir and not names:
            self.add(rel_dir, 'empty-directory', note='a submodule/gitlink exports as an empty directory')
        for name in names:
            rel = rel_dir + '/' + name if rel_dir else name
            path = os.path.join(full, name)
            try:
                rel.encode('utf-8')
            except UnicodeEncodeError:
                self.add(rel.encode('utf-8', 'surrogateescape').decode('utf-8', 'replace'), 'bad-file-name',
                         note='not UTF-8')
                continue
            if re.search(r'[\x00-\x1f\x7f:]', name):
                self.add(rel.replace('\n', '?'), 'bad-file-name', note='control character or colon')
                continue
            self.check_path_name(rel)
            if name.lower() == 'personal':
                self.add(rel, 'personal-directory', note="the owner's personal layer is never published")
            if name in ('.git', '.gitmodules', '.hg', '.svn'):
                self.add(rel, 'vcs-metadata')
            st = os.lstat(path)
            if stat.S_ISLNK(st.st_mode):
                self.add(rel, 'symlink')
            elif stat.S_ISDIR(st.st_mode):
                self._walk(root, rel)
            elif not stat.S_ISREG(st.st_mode):
                self.add(rel, 'special-file')
            else:
                self.check_policy(rel, name)
                self.scan_file(rel, path, st)

    # --- content -----------------------------------------------------------------------------
    def scan_file(self, rel, path, st):
        if st.st_size > MAX_BYTES:
            self.add(rel, 'too-large', note='> %d KB' % (MAX_BYTES // 1024))
            return
        with open(path, 'rb') as fh:
            data = fh.read()
        binary_ok = rel in self.cfg.binary
        texts = []
        for magic, kind in COMPRESSED_MAGIC:
            if data.startswith(magic):
                self.add(rel, 'compressed-or-database', note=kind)
                inner = inflate(data, kind)
                if inner is not None:
                    texts.append(('inflated', decode_any(inner)))
                break
        if data[:2] in (b'\xff\xfe', b'\xfe\xff') or looks_utf16(data):
            self.add(rel, 'utf16')
            for enc in ('utf-16-le', 'utf-16-be'):
                texts.append(('utf16', data.decode(enc, errors='ignore')))
        if b'\x00' in data:
            if not binary_ok:
                self.add(rel, 'nul-bytes', note='binary or UTF-16; grep -I would skip it')
            texts.append(('bytes', data.replace(b'\x00', b'').decode('latin-1')))
        else:
            try:
                text = data.decode('utf-8')
            except UnicodeDecodeError:
                if not binary_ok:
                    self.add(rel, 'not-utf8', note='encoding error; grep -I would skip it')
                texts.append(('latin1', data.decode('latin-1')))
            else:
                body = text[1:] if text.startswith('\ufeff') else text
                lm = line_mapper(body)
                for m in INVISIBLE_RE.finditer(body):
                    cp = 'U+%04X' % ord(m.group())
                    if (rel, cp) not in self.cfg.invisible_ok:
                        self.add(rel, 'invisible-character', lm(m.start()), cp)
                texts.insert(0, ('text', body))
                if os.path.basename(rel) == '.env.example':
                    self.check_env_example(rel, body)
        for origin, text in texts:
            masks = self.masks_for(rel, text) if origin == 'text' else {}
            self.scan_text(rel, text, origin, masks)

    def scan_text(self, rel, text, origin, masks):
        lines = text.split('\n')
        for cat in CATEGORIES:
            rules = [r for r in self.cfg.rules if r.cat == cat]
            mask = masks.get(cat)
            t = '\n'.join('' if i in mask else l for i, l in enumerate(lines)) if mask else text
            for view, vt, to_line in make_views(t):
                if view.endswith('joined') and cat == 'email':
                    continue  # joining lines glues an address to the next word; not a useful view here
                tag = view if origin == 'text' else origin + '/' + view
                for r in rules:
                    for pos in r.hits(vt):
                        self.add(rel, r.label, to_line(pos), tag)
            raw_line = line_mapper(t)
            for pos, decoded in base64_fragments(t) + base64_fragments(make_views(t)[1][1]):
                tag = 'base64' if origin == 'text' else origin + '/base64'
                for _, vt, _ in make_views(decoded):
                    for r in rules:
                        if next(r.hits(vt), None) is not None:
                            self.add(rel, r.label, raw_line(pos), tag)

    def masks_for(self, rel, text):
        """Exact lines that are exempt from one rule category only."""
        lines = text.split('\n')
        masks = {'name': set(), 'email': set(), 'facebook': set()}
        for path, cats, expected in self.cfg.exact_lines:
            if path == rel:
                for i, l in enumerate(lines):
                    if l.rstrip('\r') == expected:
                        for c in cats:
                            masks[c].add(i)
        if rel == 'scripts/public-allowlist.txt':
            # The allowlist's own exact-line entries carry exactly the text they allow elsewhere.
            for i, l in enumerate(lines):
                if l.startswith('exact-line:'):
                    for c in EXACT_LINE_CATEGORIES:
                        masks[c].add(i)
        if rel == 'LICENSE' or re.fullmatch(r'README[^/]*\.md', rel):
            prefix = r'Copyright \(c\) [0-9]{4} ' if rel == 'LICENSE' else r'(?:Author: |作者：)'
            for i, l in enumerate(lines):
                if any(re.fullmatch(prefix + re.escape(o), l.rstrip('\r')) for o in self.cfg.owners):
                    masks['name'].add(i)
        if os.path.basename(rel) == 'composer.lock':
            indent = None
            for i, l in enumerate(lines):
                if indent is None:
                    m = re.match(r'^(\s*)"authors"\s*:\s*\[\s*$', l)
                    if m:
                        indent = m.group(1)
                    continue
                if re.match(r'^' + re.escape(indent) + r'\]', l):
                    indent = None
                elif re.fullmatch(r'\s*"email"\s*:\s*"[^"\s@]+@[^"\s]+"\s*,?\s*', l):
                    masks['email'].add(i)
        return masks

    def check_env_example(self, rel, text):
        for n, line in enumerate(text.split('\n'), 1):
            s = line.strip()
            if not s:
                continue
            commented = s.startswith('#')
            body = s.lstrip('#').strip() if commented else s
            m = ENV_LINE_RE.fullmatch(body)
            if m:
                pairs = [(m.group(1), m.group(2).strip())]
            elif commented:
                # KEY=value written inside a comment's text: each one gets the same value check.
                pairs = [(k, v.rstrip('.,;:!?)')) for k, v in ENV_INLINE_RE.findall(body)]
            else:
                self.add(rel, 'env-example', n, 'not a KEY=value line')
                continue
            for key, value in pairs:
                if len(value) >= 2 and value[0] == value[-1] and value[0] in '"\'`':
                    value = value[1:-1]
                if value == '' or value in self.cfg.env_values or ENV_GENERIC_OK_RE.fullmatch(value):
                    continue
                self.add(rel, 'env-example', n, 'value of %s is not an allowed placeholder' % key)


def looks_utf16(data):
    if len(data) < 8:
        return False
    half = len(data) // 2
    return data[1::2].count(0) > 0.6 * half or data[0::2].count(0) > 0.6 * half


def decode_any(data):
    try:
        return data.decode('utf-8')
    except UnicodeDecodeError:
        return data.replace(b'\x00', b'').decode('latin-1')


def inflate(data, kind):
    try:
        if kind == 'gzip':
            return zlib.decompressobj(16 + zlib.MAX_WBITS).decompress(data, MAX_INFLATE)
        if kind == 'xz':
            return lzma.LZMADecompressor().decompress(data, MAX_INFLATE)
        if kind == 'zip':
            out = []
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                for info in zf.infolist():
                    out.append(info.filename.encode('utf-8', 'replace'))
                    if info.file_size <= MAX_INFLATE:
                        out.append(zf.read(info))
            return b'\n'.join(out)
    except Exception:
        return None
    return None


# ---------------------------------------------------------------------------------------------
# Export
# ---------------------------------------------------------------------------------------------
def strip_private_lines(root, privates):
    lowered = [p.lower() for p in privates]
    for dirpath, dirnames, filenames in os.walk(root):
        for fn in filenames:
            if fn not in ('.gitattributes', '.gitignore'):
                continue
            path = os.path.join(dirpath, fn)
            if os.path.islink(path):
                continue
            with open(path, encoding='utf-8', errors='surrogateescape') as fh:
                lines = fh.read().split('\n')
            kept = [l for l in lines if not any(p in l.lower() for p in lowered)]
            if len(kept) != len(lines):
                with open(path, 'w', encoding='utf-8', errors='surrogateescape') as fh:
                    fh.write('\n'.join(kept))
                print('Removed %d private-only line(s) from %s' % (len(lines) - len(kept),
                                                                   os.path.relpath(path, root)))


def export(cfg, git, treeish, out, repo_root=None):
    """git: argv prefix such as ['git', '-C', repo]. Returns a Scanner holding the findings."""
    if os.path.lexists(out):
        if not os.path.isdir(out) or os.path.islink(out):
            die('output exists and is not a directory: ' + out)
        if os.listdir(out):
            die('output directory is not empty: ' + out)
    os.makedirs(out, exist_ok=True)
    out = os.path.realpath(out)
    if repo_root and (out + '/').startswith(os.path.realpath(repo_root) + '/'):
        die('output directory must be outside the repository: ' + out)

    sc = Scanner(cfg)
    listing = subprocess.run(git + ['ls-tree', '-r', '-z', '--full-tree', treeish], capture_output=True)
    if listing.returncode != 0:
        die('git ls-tree failed: ' + listing.stderr.decode('utf-8', 'replace').strip())
    for entry in listing.stdout.split(b'\0'):
        if not entry:
            continue
        meta, path = entry.split(b'\t', 1)
        mode = meta.split(b' ')[0]
        rel = path.decode('utf-8', 'replace')
        if mode == b'120000':
            sc.add(rel, 'symlink-in-git-tree')
        elif mode == b'160000':
            sc.add(rel, 'gitlink-in-git-tree', note='submodule')
        if rel == '.gitmodules' or rel.endswith('/.gitmodules'):
            sc.add(rel, 'gitmodules-in-git-tree')

    archive = subprocess.Popen(git + ['archive', '--format=tar', treeish], stdout=subprocess.PIPE)
    tar = subprocess.run(['tar', '-x', '--no-same-owner', '-C', out], stdin=archive.stdout)
    archive.stdout.close()
    if archive.wait() != 0 or tar.returncode != 0:
        die('git archive | tar failed')
    strip_private_lines(out, cfg.privates)
    short = subprocess.run(git + ['rev-parse', '--short', treeish], capture_output=True, text=True).stdout.strip()
    print('Exported %s (%s) to %s' % (treeish, short, out))
    sc.scan_tree(out)
    return sc


# ---------------------------------------------------------------------------------------------
# Selftest
# ---------------------------------------------------------------------------------------------
def R(s):
    """Dirty selftest literals are stored reversed so this script never contains them as written."""
    return s[::-1]


def fullwidth(s):
    return ''.join(chr(ord(c) + 0xFEE0) if '!' <= c <= '~' else c for c in s)


def write_tree(base, files):
    for rel, content in files.items():
        path = os.path.join(base, rel)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        if isinstance(content, tuple) and content[0] == 'symlink':
            os.symlink(content[1], path)
        else:
            with open(path, 'wb') as fh:
                fh.write(content.encode('utf-8') if isinstance(content, str) else content)


def git_tree(tmp, name, files):
    """Build a tree object in a scratch bare repository (no commit, no work tree)."""
    gitdir = os.path.join(tmp, name + '.git')
    subprocess.run(['git', 'init', '-q', '--bare', gitdir], check=True)
    env = dict(os.environ, GIT_INDEX_FILE=os.path.join(tmp, name + '.index'))
    git = ['git', '--git-dir', gitdir]
    for rel, content in files.items():
        if isinstance(content, tuple):
            kind, value = content
            if kind == 'gitlink':
                mode, sha = '160000', value
            else:
                mode = '120000'
                sha = subprocess.run(git + ['hash-object', '-w', '--stdin'], input=value.encode(),
                                     capture_output=True, check=True).stdout.decode().strip()
        else:
            data = content.encode('utf-8') if isinstance(content, str) else content
            mode = '100644'
            sha = subprocess.run(git + ['hash-object', '-w', '--stdin'], input=data,
                                 capture_output=True, check=True).stdout.decode().strip()
        subprocess.run(git + ['update-index', '--add', '--cacheinfo', '%s,%s,%s' % (mode, sha, rel)],
                       env=env, check=True)
    tree = subprocess.run(git + ['write-tree'], env=env, capture_output=True, check=True).stdout.decode().strip()
    return git, tree


def selftest(cfg, tmp, script_path, allowlist_path):
    H = cfg.keyed.get('host')
    O = cfg.owners[0]
    if not H or not cfg.samples:
        print('FAIL setup: the denylist needs "# selftest: host: <text>" and other "# selftest:" lines')
        return 1
    host = {r.label for r in cfg.rules if r.cat == 'default' and next(r.hits(H), None) is not None}
    name = {r.label for r in cfg.rules if r.cat == 'name'}
    if not host:
        print('FAIL setup: no denylist rule catches the selftest host sample')
        return 1
    cut = H.rfind('.') if '.' in H else len(H) // 2
    H1, H2 = H[:cut], H[cut:]
    ip = '.'.join(str(x) for x in (93, 184, 215, 14))
    long_token = R('43210eulaVnekoTgnikooLlaeRzGQA')
    results = []

    def run_case(cid, desc, files, expect=(), absent=(), clean=False):
        d = os.path.join(tmp, cid)
        write_tree(d, files)
        sc = Scanner(cfg, quiet=True)
        sc.scan_tree(d)
        problems = []
        for rel, codes, *line in expect:
            codes = codes if isinstance(codes, (set, frozenset)) else {codes}
            ok = any(f['rel'] == rel and (codes == {'*'} or f['code'] in codes)
                     and (not line or f['line'] == line[0]) for f in sc.findings)
            if not ok:
                problems.append('missing %s%s %s' % (rel, (':%d' % line[0]) if line else '', sorted(codes)))
        for rel, codes, line in absent:
            if any(f['rel'] == rel and f['code'] in codes and f['line'] == line for f in sc.findings):
                problems.append('unexpected %s:%d %s' % (rel, line, sorted(codes)))
        if clean and sc.findings:
            problems.append('expected no findings')
        if not clean and not sc.findings:
            problems.append('expected exit 1, got 0')
        results.append((cid, desc, problems, sc))

    doc = 'docs/HOW-IT-WORKS.md'
    run_case('B01', 'full-width host (NFKC)', {doc: 'host: ' + fullwidth(H) + '\n'}, [(doc, host, 1)])
    run_case('B02', 'percent-encoded dots', {doc: 'url: https://' + H.replace('.', '%2E') + '/x\n'},
             [(doc, host, 1)])
    run_case('B03', 'string concatenation, also across lines',
             {'app/Probe.php': '<?php\n$h = "%s" . "%s";\n$j = \'%s\'\n    . \'%s\';\n' % (H1, H2, H1, H2)},
             [('app/Probe.php', host, 2), ('app/Probe.php', host, 3)])
    run_case('B04', 'base64-encoded host',
             {doc: 'host: ' + base64.b64encode(('host: ' + H).encode()).decode() + '\n'}, [(doc, host, 1)])
    run_case('B05', 'HTML entities',
             {'resources/views/probe.html': '<p>%s</p>\n<p>%s</p>\n' % (H.replace('.', '&#46;'),
                                                                   H.replace('.', '&#x2e;'))},
             [('resources/views/probe.html', host, 1), ('resources/views/probe.html', host, 2)])
    run_case('B06', 'zero-width character inside the host',
             {doc: 'host: ' + H[:1] + '\u200b' + H[1:] + '\n'},
             [(doc, 'invisible-character', 1), (doc, host, 1)])
    run_case('B07', 'Latin-1 file (not UTF-8)', {doc: ('path: ' + H + ' caf').encode() + b'\xe9\n'},
             [(doc, 'not-utf8'), (doc, host)])
    run_case('B08', 'UTF-16 file', {doc: ('host: ' + H + '\n').encode('utf-16-le')},
             [(doc, 'utf16'), (doc, 'nul-bytes'), (doc, host)])
    gz = gzip.compress(('host: ' + H + '\n').encode(), mtime=0)
    run_case('B09', 'compressed data, with and without .gz', {doc: gz, 'docs/deploy/notes.txt.gz': gz},
             [(doc, 'compressed-or-database'), (doc, host), ('docs/deploy/notes.txt.gz', 'extension-not-allowed')])
    run_case('B10', 'internal string in file and directory names',
             {'app/%s/Probe.php' % H: '<?php\n', 'docs/deploy/%s-notes.md' % H: 'clean\n'},
             [('app/%s' % H, 'path-name'), ('docs/deploy/%s-notes.md' % H, 'path-name')])
    run_case('B11', 'symlinks (file and directory)',
             {'public/link.md': ('symlink', '../.env'), 'resources/linked': ('symlink', '/')},
             [('public/link.md', 'symlink'), ('resources/linked', 'symlink')])
    run_case('B12', 'allowed LICENSE/README lines exempt only the exact text and only the name rule',
             {'LICENSE': 'MIT License\n\nCopyright (c) 2026 %s\nCopyright (c) 2026 Jane, deployed at %s\n'
                         'Maintained by %s\n' % (O, H, O),
              'README.md': '# Demo\nAuthor: %s\nAuthor: %s - https://%s\nAuthor: Jane %s\n' % (O, O, H, O)},
             [('LICENSE', host, 4), ('LICENSE', name, 5), ('README.md', host, 3), ('README.md', name, 3),
              ('README.md', name, 4)],
             absent=[('LICENSE', name, 3), ('README.md', name, 2)])
    run_case('B13', 'e-mail addresses on any domain',
             {doc: '\n'.join(R(x) for x in ('oi.ynapmoc@nosreplaer', 'gro.proc@spo', 'pj.oc.ycnega@x',
                                            'ved.oiduts@y')) + '\n'},
             [(doc, 'builtin:email', n) for n in (1, 2, 3, 4)])
    run_case('B14', 'xmt token forms (=, %3D, JSON, YAML, entity, FAKE prefix)',
             {doc: '\n'.join(['https://www.threads.com/t/X?' + R('=tmx') + 'FAKE' + long_token,
                              'share%3F' + R('D3%tmx') + long_token,
                              '{"' + R('tmx') + '":"' + long_token + '"}',
                              R('tmx') + ': ' + long_token,
                              R('tmx') + '&#61;' + long_token]) + '\n'},
             [(doc, 'builtin:xmt', n) for n in (1, 2, 3, 4, 5)])
    variants = [O, O.replace(' ', '.'), O.replace(' ', '_'), O.replace(' ', '-'), O.replace(' ', ''),
                ' '.join(reversed(O.split())), O.upper()]
    run_case('B15', 'owner name with separators, either order',
             {'app/Probe.php': '<?php\n' + ''.join('// written by %s\n' % v for v in variants)},
             [('app/Probe.php', name, n) for n in range(2, 2 + len(variants))])
    run_case('B16', 'secrets: .env.example values, APP_KEY, bot token, sessionid, password, IPv4, keys',
             {'.env.example': '\n'.join([R('=UVRGQ0ZLRUZBS0VGQUtFRkFLRUZBS0VGQUtFRkFLRUZB:46esab=YEK_PPA'),
                                         R('laeRtoNdr0wssaPekaF=DROWSSAP_BD'),
                                         R('21ekaFekaFekaFekaFekaFekaFekaFekaFAA:987654321=NEKOT_TOB'),
                                         'SERVER_IP=' + ip,
                                         R('fedcba0987654321EKAF=dinoisses=NOISSES')]) + '\n',
              doc: '\n'.join([R('terceS2retnuH :drowssap'),
                              R('21ekaFekaFekaFekaFekaFekaFekaFekaFAA:987654321 :nekot'),
                              'server ' + ip,
                              R('hgfedcba4321=dinoisses :eikooC'),
                              R('-----YEK ETAVIRP ASR NIGEB-----')]) + '\n'},
             [('.env.example', 'env-example', n) for n in (1, 2, 3, 4, 5)]
             + [('.env.example', 'builtin:app-key', 1), ('.env.example', 'builtin:bot-token', 3),
                (doc, 'builtin:password', 1), (doc, 'builtin:bot-token', 2), (doc, 'builtin:ipv4', 3),
                (doc, 'builtin:sessionid', 4), (doc, 'builtin:private-key', 5)])
    junk = ['database/database.sqlite', 'docs/deploy/dump.sql.gz', 'capture.har.json', 'storage/app/id_rsa',
            'docs/deploy/server.pem', 'app/app.db', 'storage/logs/laravel.log', 'public/backup.zip',
            'resources/cookies.txt', '.env', 'config/.env.production', 'database/database.sqlite-wal']
    run_case('B17', 'forbidden file types', {j: '' for j in junk}, [(j, '*') for j in junk])
    lock = ('{\n    "packages": [\n        {\n            "name": "a/b",\n            "authors": [\n'
            '                {\n                    "name": "Third Party",\n'
            '                    "email": "%s"\n                }\n            ],\n'
            '            "support": {\n                "email": "%s"\n            }\n        }\n    ],\n'
            '    "x": {"email": "%s"}\n}\n') % (R('oi.tigelrodnev@ved'), R('oi.hsiproc@nosreptavirp'),
                                              R('oi.hsiproc@nosreptavirp'))
    run_case('B18', 'composer.lock e-mail outside an "authors" block', {'composer.lock': lock},
             [('composer.lock', 'builtin:email', 12), ('composer.lock', 'builtin:email', 16)],
             absent=[('composer.lock', {'builtin:email'}, 8)])
    run_case('B19', 'owner name inside an allowed example.com address',
             {doc: 'admin: %s@example.com\n' % O.lower().replace(' ', '.')}, [(doc, name, 1)])
    run_case('B20', 'NUL byte hides the rest of the file from grep',
             {doc: b'notes\x00\nhost: ' + H.encode() + b'\n'}, [(doc, 'nul-bytes'), (doc, host)])

    # --- FX2: password forms, long secrets, IPv6, .env.example comments, escapes, review lock ---
    pw = R('77-ssaP-gnikooL-laeR')
    probe = 'tests/Feature/ProbeTest.php'
    run_case('E1a', 'password forms in code: putenv, $_ENV[...] =, env() default (N14 N15 N16)',
             {probe: '<?php\n' + '\n'.join([R(";)'@@=DROWSSAP_BD'(vnetup"), R(";'@@' = ]'DROWSSAP_BD'[VNE_$"),
                                             R(";])'@@' ,'DROWSSAP_BD'(vne >= 'drowssap'[ nruter")]).replace('@@', pw)
                     + '\n'},
             [(probe, 'builtin:password', n) for n in (2, 3, 4)])
    run_case('E1b', 'password values: $ and % prefixes, ini, URL user:password@ on any host (N17 N18 N19 N19b)',
             {doc: '\n'.join([R('@@$ :drowssap'), R('@@% :drowssap'), R('ppa/moc.elpmaxe.bd@@@:nimda//:lqsym :NSD'),
                              R('ppa/1.0.0.721@@@:toor//:lqsym :NSD'), R('@@ = drowssap_bd')]).replace('@@', pw)
                   + '\n'},
             [(doc, 'builtin:password', n) for n in (1, 2, 3, 4, 5)])
    run_case('E1c', 'password look-alikes that are placeholders or references pass',
             {probe: "<?php\nputenv('DB_PASSWORD=fake-env-pass-5432');\n$_ENV['DB_PASSWORD'] = 'fake-env-pass-5432';\n"
                     "$x = env('DB_PASSWORD', 'change-me');\n"
                     "$t = env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens');\n"
                     "$ok = $password === $_ENV['DB_PASSWORD'];\n",
              doc: 'password: $DB_PASSWORD\npassword: ${DB_PASSWORD}\npassword: %env(DB_PASSWORD)%\n'
                   'password: {{ db_password }}\npassword: <your-password>\n'
                   'DSN: mysql://user:password@example.com/app and https://u:p@example.com/x\n'},
             clean=True)
    hex32 = hashlib.md5(b'selftest-secret').hexdigest()
    b64 = base64.urlsafe_b64encode(hashlib.sha256(b'selftest-token').digest()).decode().rstrip('=')
    cfgp = 'config/probe.php'
    run_case('E2', 'long hex/base64 values after secret words (N13)',
             {cfgp: "<?php\nreturn ['client_%s' => '%s',\n    'api_%s' => \"%s\",\n" % ('secret', hex32, 'token', b64)
                    + "    // %s: %s\n    'webhook_%s' => '%s'];\n" % ('AUTH_KEY', hex32.upper(), 'credential', b64)},
             [(cfgp, 'builtin:high-entropy-secret', n) for n in (2, 3, 4, 5)])
    fake_secret = 'FAKE' + hex32[:28]
    cfg.password_values.add(fake_secret)
    run_case('E2c', 'secret words with references, short or non-random values, exact fake values pass',
             {cfgp: "<?php\nreturn ['secret' => env('APP_SECRET'),\n    'key' => 'threads_monitor_session_cookie_name',\n"
                    "    'content-hash' => '%s',\n    'token' => '%s',\n    'auth' => 'abc123'];\n" % (hex32, fake_secret)},
             clean=True)
    cfg.password_values.discard(fake_secret)
    v6 = ':'.join('%x' % x for x in (0x2a01, 0x4f8, 0xc17, 0x1234)) + '::1'
    v6b = ':'.join('%x' % x for x in (0x2600, 0x1f18)) + '::7'
    mapped = '::' + 'ffff:' + ip
    run_case('E3', 'global IPv6 addresses, also bracketed and IPv4-mapped (N20)',
             {doc: 'server: %s\nurl: http://[%s]:443/\nclient %s\n' % (v6, v6b, mapped)},
             [(doc, 'builtin:ipv6', n) for n in (1, 2, 3)])
    run_case('E3c', 'loopback, documentation, link-local, unique-local IPv6 and look-alikes pass',
             {doc: 'loopback ::1, docs 2001:db8::1, link fe80::1%eth0, ula fd12:3456::1, unspecified ::\n'
                   'mapped ::ffff:203.0.113.7, time 12:34:56, mac 00:1a:2b:3c:4d:5e\n',
              'resources/css/app.css': 'a::after { content: ""; }\n',
              'standalone/scripts/probe.sh': 'python3 -c "print(data[1::2])"\n'},
             clean=True)
    env_dirty = '\n'.join([
        '# Example: set it like THREADS_POLL_BANDS=0-24:60 and API_' + 'SECRET=' + hex32,
        '# For SMTP use ' + R('@@=DROWSSAP_LIAM').replace('@@', pw) + '.',
        'APP_KEY='])
    run_case('E4', '.env.example KEY=value inside comment text is checked too (N12)',
             {'.env.example': env_dirty + '\n'},
             [('.env.example', 'env-example', 1), ('.env.example', 'env-example', 2)])
    run_case('E4c', '.env.example comments with allowlisted example values pass',
             {'.env.example': '# day, then every 3 hours: THREADS_POLL_BANDS=0-24:60,24-72:180\n'
                              '# Uncomment, e.g. `THREADS_FOOTER_ENABLED=false`, or DB_PASSWORD=change-me.\n'
                              '# DB_HOST=127.0.0.1\nAPP_URL=http://localhost\n'},
             clean=True)
    lj = 'lang/zh_TW.json'
    run_case('E5a', 'JSON/PHP/JS escapes: \\uXXXX, \\u{X}, \\xXX, octal (N02 N03 N04)',
             {lj: '{"site": "%s"}\n' % ''.join('\\u%04x' % ord(c) for c in H),
              'app/Probe.php': '<?php\n$a = "%s";\n$b = "%s";\n$c = "%s";\n' % (
                  ''.join('\\x%02x' % ord(c) for c in H), ''.join('\\%03o' % ord(c) for c in H),
                  ''.join('\\u{%x}' % ord(c) for c in H))},
             [(lj, host, 1)] + [('app/Probe.php', host, n) for n in (2, 3, 4)])
    run_case('E5b', 'CSS escapes (N32)',
             {'resources/css/app.css': 'a::after{content:"%s"}\n' % ''.join('\\%x ' % ord(c) for c in H)},
             [('resources/css/app.css', host, 1)])
    cyr = {'a': 'а', 'c': 'с', 'e': 'е', 'o': 'о', 'p': 'р', 'x': 'х',
           'y': 'у', 'i': 'і', 's': 'ѕ'}
    grk = {'a': 'α', 'o': 'ο', 'i': 'ι', 'k': 'κ', 'v': 'ν', 't': 'τ'}
    run_case('E5c', 'Cyrillic and Greek look-alike letters (N09)',
             {doc: 'host: %s\nhost: %s\n' % (''.join(cyr.get(c, c) for c in H), ''.join(grk.get(c, c) for c in H))},
             [(doc, host, 1), (doc, host, 2)])
    run_case('E5d', 'harmless escapes and Greek/Cyrillic words pass',
             {lj: '{"hi": "\\u0048\\u0065llo \\u4f60\\u597d", "nl": "a\\nb\\x41\\101"}\n',
              'resources/css/app.css': 'a::before { content: "\\2014 \\00a0"; }\n',
              doc: 'Greek αβγ, Cyrillic привет\n'},
             clean=True)

    # Review lock for the allowlist's exempting entries (N28).
    with open(allowlist_path, encoding='utf-8') as fh:
        allow_text = fh.read()
    with open(lock_path_for(allowlist_path), 'rb') as fh:
        lock_bytes = fh.read()
    smuggle = 'exact-line:x.php|name,facebook|written by %s https://www.%s/%s\n' % (
        O, R('moc.koobecaf'), R('nosrep.laer'))
    al = 'scripts/public-allowlist.txt'
    run_case('E6a', 'an exact-line entry added to the published allowlist without updating the lock (N28)',
             {al: allow_text + smuggle, 'scripts/public-allowlist.lock': lock_bytes},
             [(al, 'allowlist-lock-mismatch')])
    run_case('E6b', 'a published allowlist without its review lock',
             {al: 'app/**\next:php\n' + smuggle}, [(al, 'allowlist-lock-mismatch')])
    run_case('E6c', 'the published allowlist and its review lock agree',
             {al: allow_text, 'scripts/public-allowlist.lock': lock_bytes}, clean=True)
    # The configured allowlist: a changed password-value fails until --update-allowlist-lock, which
    # rewrites only the lock file.
    work = os.path.join(tmp, 'lock-work')
    os.makedirs(work)
    wa = os.path.join(work, 'public-allowlist.txt')
    with open(wa, 'w', encoding='utf-8') as fh:
        fh.write(allow_text)
    write_lock(wa)
    problems, sc = [], Scanner(cfg, quiet=True)
    if not check_lock(wa, sc, quiet=True):
        problems.append('fresh lock does not match')
    with open(wa, 'a', encoding='utf-8') as fh:
        fh.write('password-value:%s\n' % pw)
    with open(wa, 'rb') as fh:
        before = fh.read()
    if check_lock(wa, sc, quiet=True):
        problems.append('changed password-value entry not detected')
    write_lock(wa)
    with open(wa, 'rb') as fh:
        if fh.read() != before:
            problems.append('--update-allowlist-lock changed the allowlist')
    if not check_lock(wa, Scanner(cfg, quiet=True), quiet=True):
        problems.append('updated lock does not match')
    results.append(('E6d', 'configured allowlist: change detected, update rewrites only the lock', problems, sc))

    # Private denylist samples, all in one file and one per file.
    files = {doc: 'line one\n' + '\n'.join(cfg.samples) + '\n'}
    for i, s in enumerate(cfg.samples):
        files['docs/SELF-HOSTING.sample-%d.md' % i] = 'line one\n%s\n' % s
    run_case('X01', 'private denylist samples (%d)' % len(cfg.samples), files,
             [(doc, '*', n + 2) for n in range(len(cfg.samples))]
             + [('docs/SELF-HOSTING.sample-%d.md' % i, '*', 2) for i in range(len(cfg.samples))])
    run_case('X02', 'paths and extensions outside the allowlist',
             {'CLAUDE.md': 'x\n', '.claude/settings.json': '{}\n', 'docs/notes.md': 'x\n', 'app/x.mjs': 'x\n',
              'Docs/HOW-IT-WORKS.md': 'x\n', 'notes': 'x\n', 'app/.gitmodules': 'x\n'},
             [('CLAUDE.md', 'not-allowlisted'), ('.claude/settings.json', 'not-allowlisted'),
              ('docs/notes.md', 'not-allowlisted'), ('app/x.mjs', 'extension-not-allowed'),
              ('Docs/HOW-IT-WORKS.md', 'not-allowlisted'), ('notes', 'extension-not-allowed'),
              ('app/.gitmodules', 'vcs-metadata')])
    run_case('X06', "personal layer is excluded even inside allowed directories",
             {'resources/views/personal/x.blade.php': '<div></div>\n', 'public/personal/p.css': 'a{}\n',
              'resources/css/personal/p.css': 'a{}\n', 'app/Personal/X.php': '<?php\n'},
             [('resources/views/personal/x.blade.php', 'excluded-path'), ('public/personal/p.css', 'excluded-path'),
              ('resources/css/personal/p.css', 'excluded-path'), ('resources/views/personal', 'personal-directory'),
              ('public/personal', 'personal-directory'), ('app/Personal', 'personal-directory')])
    FB = cfg.keyed.get('fb')
    if FB:
        fb_rules = {r.label for r in cfg.rules if r.cat == 'facebook'}
        exact = "    'footer' => '%s - %s'," % (O, FB)
        cfg.exact_lines.append(('config/threads.php', frozenset(['name', 'facebook']), exact))
        run_case('X07', 'exact-line exemption: only that line, that file, the name and facebook rules',
                 {'config/threads.php': "<?php\nreturn [\n%s\n    'author' => '%s',\n    'link' => '%s',\n"
                                        "%s // https://%s\n];\n" % (exact, O, FB, exact, H),
                  doc: exact + '\n'},
                 [('config/threads.php', name, 4), ('config/threads.php', fb_rules, 5),
                  ('config/threads.php', name, 6), ('config/threads.php', fb_rules, 6),
                  ('config/threads.php', host, 6), (doc, name, 1), (doc, fb_rules, 1)],
                 absent=[('config/threads.php', name | fb_rules, 3)])
        cfg.exact_lines.pop()
    else:
        results.append(('X07', 'exact-line exemption', ['denylist needs a "# selftest: fb: <url>" line'], Scanner(cfg)))
    with open(script_path, 'rb') as fh:
        script_bytes = fh.read()
    with open(allowlist_path, 'rb') as fh:
        allow_bytes = fh.read()
    run_case('X03', 'the public script, allowlist and review lock contain no internal strings',
             {'scripts/export-public.sh': script_bytes, 'scripts/public-allowlist.txt': allow_bytes,
              'scripts/public-allowlist.lock': lock_bytes},
             clean=True)
    run_case('CONTROL', 'clean tree with allowed look-alikes passes',
             {'README.md': '# Demo\nAuthor: %s\n\nAdmin: admin@example.com, user@example.test, x@foo.example,'
                           ' someone@threads.com\nhttps://www.threads.com/t/DFakeP0st01?xmt=FAKEXMTVALUE01'
                           '&slof=1 and ?xmt=abc\nLocal: 127.0.0.1, docs range 203.0.113.5\n' % O,
              'README.zh-TW.md': '# 示範\n作者：%s\n' % O,
              'LICENSE': 'MIT License\n\nCopyright (c) 2026 %s\n' % O,
              '.env.example': 'APP_NAME="Threads Monitor"\nAPP_KEY=\nDB_CONNECTION=sqlite\n# DB_PASSWORD=change-me\n'
                              'MAIL_FROM_ADDRESS="hello@example.com"\nVITE_APP_NAME="${APP_NAME}"\nTIMEOUT=30\n',
              'composer.lock': lock.split('            "support"')[0] + '        }\n    ]\n}\n',
              'tests/Unit/ProbeTest.php': "<?php\n$u = ['password' => 'password'];\n$s = 'abc' . 'def';\n",
              'public/favicon.ico': b'\x00\x00\x01\x00',
              'resources/css/app.css': '\ufeff@media (min-width: 640px) { a { color: red; } }\n'},
             clean=True)

    # Export pipeline: symlinks, gitlinks and .gitmodules in the git tree fail the export.
    git, tree = git_tree(tmp, 'repo-bad', {'README.md': '# Demo\n', 'app/link.php': ('symlink', '../.env'),
                                           'vendor-sub': ('gitlink', '1' * 40),
                                           '.gitmodules': '[submodule "vendor-sub"]\n'})
    sc = export(cfg, git, tree, os.path.join(tmp, 'out-bad'))
    need = [('app/link.php', 'symlink-in-git-tree'), ('vendor-sub', 'gitlink-in-git-tree'),
            ('.gitmodules', 'gitmodules-in-git-tree'), ('app/link.php', 'symlink'),
            ('vendor-sub', 'empty-directory')]
    problems = ['missing %s %s' % n for n in need
                if not any(f['rel'] == n[0] and f['code'] == n[1] for f in sc.findings)]
    results.append(('X04', 'export: symlink, gitlink and .gitmodules in the git tree', problems, sc))

    # Export pipeline: a clean tree passes; private-only lines leave .gitattributes and .gitignore.
    attrs = '* text=auto eol=lf\n/.github export-ignore\n' + ''.join(
        '/%s.md export-ignore\n' % p for p in cfg.privates)
    ignore = '*.log\n/vendor\n' + ''.join('%s/**/out/\n' % p for p in cfg.privates)
    good = {'README.md': '# Demo\nAuthor: %s\n' % O, 'LICENSE': 'MIT License\n\nCopyright (c) 2026 %s\n' % O,
            '.gitattributes': attrs, '.gitignore': ignore, 'app/Probe.php': '<?php\n'}
    for p in cfg.privates:
        good[p + '.md'] = 'private\n'
    git, tree = git_tree(tmp, 'repo-good', good)
    out = os.path.join(tmp, 'out-good')
    sc = export(cfg, git, tree, out)
    problems = [] if not sc.findings else ['expected no findings']
    for fn in ('.gitattributes', '.gitignore'):
        with open(os.path.join(out, fn), encoding='utf-8') as fh:
            body = fh.read().lower()
        if any(p.lower() in body for p in cfg.privates):
            problems.append('private-only line left in exported ' + fn)
        if '/vendor' not in body and '.github' not in body:
            problems.append('public lines lost from exported ' + fn)
    if any(os.path.exists(os.path.join(out, p + '.md')) for p in cfg.privates):
        problems.append('export-ignored private file was exported')
    results.append(('X05', 'export: clean tree passes, private-only lines removed', problems, sc))

    passed = 0
    for cid, desc, problems, sc in results:
        if problems:
            print('FAIL %-7s %s: %s' % (cid, desc, '; '.join(problems)))
            for f in sc.findings:
                print('    %s:%s [%s]' % (f['rel'], f['line'] or '', f['code']))
        else:
            passed += 1
            print('PASS %-7s %s (%d finding%s)' % (cid, desc, len(sc.findings), '' if len(sc.findings) == 1 else 's'))
    print('selftest: %d passed, %d failed' % (passed, len(results) - passed))
    return 0 if passed == len(results) else 1


# ---------------------------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------------------------
def main():
    mode, denylist, allowlist, repo, script_path, arg = sys.argv[1:7]
    if mode == 'update-lock':
        Config(denylist, allowlist)  # refuse to lock an allowlist that does not even load
        lock, entries = write_lock(allowlist)
        print('Wrote %s (%d entries). Commit it together with the reviewed allowlist.' % (lock, len(entries)))
        return 0
    if mode == 'selftest':
        cfg = Config(denylist, allowlist)
        return selftest(cfg, arg, script_path, allowlist)
    cfg = Config(denylist, allowlist, repo)
    if mode == 'scan':
        sc = Scanner(cfg)
        check_lock(allowlist, sc)
        sc.scan_tree(arg)
        sc.report()
        if sc.findings:
            return 1
        print('OK: no findings.')
        return 0
    if mode == 'export':
        sc = export(cfg, ['git', '-C', repo], 'HEAD', arg, repo_root=repo)
        check_lock(allowlist, sc)
        sc.report()
        if sc.findings:
            print('FAILED: fix the findings above in the private repo, commit, and export again.',
                  file=sys.stderr)
            return 1
        count = sum(len(f) for _, _, f in os.walk(os.path.realpath(arg)))
        print('OK: no findings in %d files.' % count)
        return 0
    die('unknown mode ' + mode)


sys.exit(main())
PYCODE

# ---------------------------------------------------------------------------------------------
# Selftest scratch directory: only ever remove the directory mktemp created for us under /tmp.
# ---------------------------------------------------------------------------------------------
SELFTEST_DIR=""
selftest_cleanup() {
    if [[ -n "$SELFTEST_DIR" && "$SELFTEST_DIR" == /tmp/* && "$SELFTEST_DIR" =~ ^/tmp/threads-export-selftest\.[A-Za-z0-9]+$ && -d "$SELFTEST_DIR" ]]; then
        rm -rf -- "$SELFTEST_DIR"
    fi
}

run_checker() { python3 -c "$CHECKER" "$@"; }

rc=0
case "$1" in
    --selftest)
        [[ $# -eq 1 ]] || usage
        SELFTEST_DIR="$(mktemp -d /tmp/threads-export-selftest.XXXXXX)"
        trap selftest_cleanup EXIT
        run_checker selftest "$DENYLIST" "$ALLOWLIST" "$REPO_ROOT" "$SCRIPT_PATH" "$SELFTEST_DIR" || rc=$?
        ;;
    --update-allowlist-lock)
        [[ $# -eq 1 ]] || usage
        run_checker update-lock "$DENYLIST" "$ALLOWLIST" "$REPO_ROOT" "$SCRIPT_PATH" "" || rc=$?
        ;;
    --scan)
        [[ $# -eq 2 ]] || usage
        run_checker scan "$DENYLIST" "$ALLOWLIST" "$REPO_ROOT" "$SCRIPT_PATH" "$2" || rc=$?
        ;;
    -*) usage ;;
    *)
        [[ $# -eq 1 ]] || usage
        run_checker export "$DENYLIST" "$ALLOWLIST" "$REPO_ROOT" "$SCRIPT_PATH" "$1" || rc=$?
        ;;
esac
exit "$rc"
