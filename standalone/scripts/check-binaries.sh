#!/usr/bin/env bash
# Scans build outputs for strings that must never ship: paths of the build machine, user names.
#
# Usage: scripts/check-binaries.sh [--needle TEXT]... [FILE_OR_DIR]...
#   --needle TEXT        a string to look for (repeatable). Also read from CHECK_BINARIES_NEEDLES
#                        (one per line). Default when none is given: the current directory, $HOME,
#                        /home/ and /tmp/. A needle ending in / matches only when a path follows it.
#   FILE_OR_DIR          what to scan. Default: src/*/obj/Release/**/ThreadsMonitor.dll, obj/publish/** and dist/**.
#
# Every file is searched as is, and so is everything hidden inside it: embedded portable PDBs
# (MPDB blocks, raw deflate) and the files of a .NET single-file bundle (compressed or not),
# recursively. Each needle is searched as UTF-8 and as UTF-16LE (inside a PDB, its path segments too).
# Exit code: 0 = no hits, 1 = hits found, 2 = usage or environment error.
set -euo pipefail

cd "$(dirname "$0")/.."

needles=()
targets=()
while [ $# -gt 0 ]; do
  case "$1" in
    --needle) [ $# -ge 2 ] || { echo "check-binaries: --needle needs a value" >&2; exit 2; }; needles+=("$2"); shift 2 ;;
    --needle=*) needles+=("${1#--needle=}"); shift ;;
    -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
    *) targets+=("$1"); shift ;;
  esac
done
if [ -n "${CHECK_BINARIES_NEEDLES:-}" ]; then
  while IFS= read -r line; do [ -n "$line" ] && needles+=("$line"); done <<< "$CHECK_BINARIES_NEEDLES"
fi
if [ ${#needles[@]} -eq 0 ]; then
  needles=("$(pwd)" "/home/" "/tmp/")
  [ -n "${HOME:-}" ] && needles+=("$HOME")
fi
if [ ${#targets[@]} -eq 0 ]; then
  while IFS= read -r f; do targets+=("$f"); done < <(find src obj -path '*/obj/Release/*' -name ThreadsMonitor.dll -type f 2>/dev/null; find obj/publish dist -type f 2>/dev/null)
fi
if [ ${#targets[@]} -eq 0 ]; then
  echo "check-binaries: nothing to scan (build first)" >&2
  exit 2
fi
command -v python3 >/dev/null || { echo "check-binaries: python3 is required" >&2; exit 2; }

printf '%s\0' "${needles[@]}" > "${TMPDIR:-/tmp}/check-binaries-needles.$$"
trap 'rm -f "${TMPDIR:-/tmp}/check-binaries-needles.$$"' EXIT

python3 - "${TMPDIR:-/tmp}/check-binaries-needles.$$" "${targets[@]}" <<'PY'
import os, re, struct, sys, zlib

needle_file, targets = sys.argv[1], sys.argv[2:]
needles = [n for n in open(needle_file, 'rb').read().decode('utf-8').split('\0') if n]
# A needle that ends with '/' (a directory such as /tmp/) only counts when a path segment follows it:
# the runtime itself contains the bare string "/tmp/" (its temp folder fallback), which is no leak.
patterns = []
for i, n in enumerate(needles):
    u8, u16 = re.escape(n.encode('utf-8')), re.escape(n.encode('utf-16-le'))
    if n.endswith('/'):
        u8 += rb'[A-Za-z0-9._~-]'
        u16 += rb'[A-Za-z0-9._~-]\x00'
    patterns.append((i, re.compile(u8)))
    patterns.append((i, re.compile(u16)))

BUNDLE_SIGNATURE = bytes([0x8b, 0x12, 0x02, 0xb9, 0x6a, 0x61, 0x20, 0x38, 0x72, 0x7b, 0x93, 0x02, 0x14,
                          0xd7, 0xa0, 0x32, 0x13, 0xf5, 0xb9, 0xe6, 0xef, 0xae, 0x33, 0x18, 0xee, 0x3b,
                          0x2d, 0xce, 0x24, 0xb3, 0x6a, 0xae])

def inflate(data):
    for wbits in (-15, 15):
        try:
            d = zlib.decompressobj(wbits)
            return d.decompress(data, 512 * 1024 * 1024)
        except zlib.error:
            pass
    return None

def read_string(b, pos):
    # 7-bit encoded length prefix, then UTF-8
    length, shift = 0, 0
    while True:
        byte = b[pos]; pos += 1
        length |= (byte & 0x7f) << shift
        if byte < 0x80:
            break
        shift += 7
    return b[pos:pos + length].decode('utf-8', 'replace'), pos + length

def bundle_entries(b):
    at = b.find(BUNDLE_SIGNATURE)
    if at < 8:
        return []
    header = struct.unpack_from('<q', b, at - 8)[0]
    if header <= 0 or header >= len(b):
        return []
    major, minor, count = struct.unpack_from('<IIi', b, header)
    pos = header + 12
    _, pos = read_string(b, pos)  # bundle id
    if major >= 2:
        pos += 8 * 4 + 8          # deps.json and runtimeconfig.json locations, flags
    entries = []
    for _ in range(count):
        offset, size = struct.unpack_from('<qq', b, pos); pos += 16
        compressed = 0
        if major >= 6:
            compressed = struct.unpack_from('<q', b, pos)[0]; pos += 8
        pos += 1                  # file type
        name, pos = read_string(b, pos)
        if compressed:
            data = inflate(b[offset:offset + compressed])
        else:
            data = b[offset:offset + size]
        if data is not None:
            entries.append((name, data))
    return entries

def embedded_pdbs(b):
    found, start = [], 0
    while True:
        at = b.find(b'MPDB', start)
        if at < 0:
            return found
        start = at + 4
        data = inflate(b[at + 8:])
        if data and data[:4] == b'BSJB':
            found.append(data)

# Portable PDBs store document paths split at the separator, so inside a PDB each path segment
# of a needle (4+ characters) is searched as well.
segments = []
for i, n in enumerate(needles):
    for part in n.replace('\\', '/').split('/'):
        if len(part) >= 4:
            segments.append((i, re.compile(re.escape(part.encode('utf-8')))))

# Strings that the .NET runtime itself ships with (not paths of the build machine).
ALLOWED = ["/tmp/coredump."]  # createdump help text: "The default is '/tmp/coredump.%p'"
allowed = [a.encode('utf-8') for a in ALLOWED] + [a.encode('utf-16-le') for a in ALLOWED]

def is_allowed(data, start):
    return any(data.startswith(a, start) for a in allowed)

hits = 0
def scan(label, data, depth=0, pdb=False):
    global hits
    for i, p in patterns + (segments if pdb else []):
        if any(not is_allowed(data, m.start()) for m in p.finditer(data)):
            hits += 1
            print(f"HIT {label}: needle #{i + 1}")
    if depth > 3:
        return
    for k, pdb in enumerate(embedded_pdbs(data)):
        scan(f"{label}!pdb{k}", pdb, depth + 1, pdb=True)
    for name, entry in bundle_entries(data):
        scan(f"{label}!{name}", entry, depth + 1)

scanned = 0
for t in targets:
    paths = [t] if os.path.isfile(t) else [os.path.join(r, f) for r, _, fs in os.walk(t) for f in fs]
    for path in paths:
        with open(path, 'rb') as fh:
            scan(path, fh.read())
        scanned += 1

print(f"check-binaries: {scanned} file(s), {len(needles)} needle(s), {hits} hit(s)")
sys.exit(1 if hits else 0)
PY
