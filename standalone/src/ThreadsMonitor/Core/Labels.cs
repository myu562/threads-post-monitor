using System.Globalization;
using System.Text;

namespace ThreadsMonitor.Core;

/// <summary>The parts of one urls.txt URL line: "&lt;url&gt; [#tag ...] [| note]".</summary>
public sealed record UrlLine(string Url, IReadOnlyList<string> Tags, string? Note);

/// <summary>
/// Tags and notes written in urls.txt, and the text rules shared by index.csv, info.txt and search.
///
///  - A tag is a "#" that follows whitespace, up to the next whitespace. A "#" inside the URL (a
///    fragment) has no whitespace before it, so it is part of the URL.
///  - Everything after the first "|" is the note (trimmed, at most 1000 characters).
///  - Tags: NFC, leading "#" removed, at most 30 characters, at most 20 per line, compared after
///    NFKC and without case, like search (the first spelling is kept). Invalid ones are reported
///    and skipped.
/// </summary>
public static class Labels
{
    public const int MaxTagLength = 30;
    public const int MaxTagsPerLine = 20;
    public const int MaxNoteLength = 1000;
    public const int IndexCaptionLength = 200;
    public const char TagSeparator = ';';

    /// <summary>Tag equality: NFKC (full-width = half-width) and no case, the same rule as search.</summary>
    public static readonly StringComparer TagComparer = new FoldedComparer();

    private sealed class FoldedComparer : StringComparer
    {
        public override int Compare(string? x, string? y) =>
            StringComparer.OrdinalIgnoreCase.Compare(x == null ? null : Fold(x), y == null ? null : Fold(y));

        public override bool Equals(string? x, string? y) =>
            StringComparer.OrdinalIgnoreCase.Equals(x == null ? null : Fold(x), y == null ? null : Fold(y));

        public override int GetHashCode(string obj) => StringComparer.OrdinalIgnoreCase.GetHashCode(Fold(obj));
    }

    /// <summary>
    /// Splits one URL line (already trimmed, not a comment or group). Problems go to
    /// <paramref name="warn"/> (English, for log.txt) and <paramref name="show"/> (in the UI language).
    /// </summary>
    public static UrlLine ParseLine(string line, Action<string>? warn = null, Action<string>? show = null)
    {
        void Report(string english, Func<Strings, string> local)
        {
            warn?.Invoke(english);
            show?.Invoke(local(Text.T));
        }

        string? note = null;
        var bar = line.IndexOf('|');
        if (bar >= 0)
        {
            note = Note(line[(bar + 1)..], Report);
            line = line[..bar];
        }

        var tokens = line.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries);
        var url = tokens.Length > 0 ? tokens[0] : "";
        var tags = new List<string>();
        var ignored = new List<string>();
        foreach (var token in tokens.Skip(1))
        {
            if (!token.StartsWith('#'))
            {
                ignored.Add(token);
                continue;
            }
            if (NormalizeTag(token) is not { } tag)
            {
                var shown = Text.Limit(Visible(token), 40);
                Report($"invalid tag \"{shown}\" skipped (1-{MaxTagLength} characters, no ; , or control/invisible characters)",
                    t => t.UrlsInvalidTag(shown));
                continue;
            }
            if (tags.Contains(tag, TagComparer))
            {
                continue;
            }
            if (tags.Count >= MaxTagsPerLine)
            {
                Report($"more than {MaxTagsPerLine} tags on one line; \"{tag}\" skipped", t => t.UrlsTooManyTags(tag));
                continue;
            }
            tags.Add(tag);
        }
        if (ignored.Count > 0)
        {
            var text = Text.Limit(string.Join(' ', ignored), 60);
            Report($"text after the URL ignored: \"{text}\" (tags start with #, a note follows |)", t => t.UrlsIgnoredText(text));
        }

        return new UrlLine(url, tags, note);
    }

    /// <summary>A tag as stored and shown (no leading #), or null when it is not valid.</summary>
    public static string? NormalizeTag(string? raw)
    {
        if (raw == null)
        {
            return null;
        }
        var tag = raw.Trim().Normalize(NormalizationForm.FormC).TrimStart('#');
        if (tag.Length == 0 || new StringInfo(tag).LengthInTextElements > MaxTagLength)
        {
            return null;
        }
        foreach (var c in tag)
        {
            if (c is ';' or ',' or '|' || char.IsControl(c) || char.IsWhiteSpace(c))
            {
                return null;
            }
        }
        // Invisible formatting characters (other than an emoji's joiner) make tags that look the same but differ.
        return StripInvisible(tag) == tag ? tag : null;
    }

    /// <summary>
    /// Removes Unicode format characters (category Cf: zero-width spaces, LRM/RLM, soft hyphen,
    /// BOM, ...), which come along when text is copied from chat apps and web pages. Kept: a zero
    /// width joiner between two emoji parts (as in a combined emoji) and the tag characters of a
    /// flag emoji.
    /// </summary>
    public static string StripInvisible(string s)
    {
        var hasFormat = false;
        foreach (var r in s.EnumerateRunes())
        {
            if (Rune.GetUnicodeCategory(r) == UnicodeCategory.Format)
            {
                hasFormat = true;
                break;
            }
        }
        if (!hasFormat)
        {
            return s;
        }

        var runes = s.EnumerateRunes().ToArray();
        var sb = new StringBuilder(s.Length);
        for (var i = 0; i < runes.Length; i++)
        {
            var r = runes[i];
            if (Rune.GetUnicodeCategory(r) != UnicodeCategory.Format
                || (r.Value == 0x200D && i > 0 && i + 1 < runes.Length && IsEmojiPart(runes[i - 1]) && IsEmojiPart(runes[i + 1]))
                || (IsTagChar(r) && i > 0 && (runes[i - 1].Value == 0x1F3F4 || IsTagChar(runes[i - 1]))))
            {
                sb.Append(r.ToString());
            }
        }
        return sb.ToString();
    }

    private static bool IsTagChar(Rune r) => r.Value is >= 0xE0020 and <= 0xE007F;

    private static bool IsEmojiPart(Rune r) =>
        r.Value is >= 0x1F000 and <= 0x1FAFF or 0xFE0F || Rune.GetUnicodeCategory(r) == UnicodeCategory.OtherSymbol;

    /// <summary>Text for a message: format characters written as &lt;U+XXXX&gt; so the reader can see them.</summary>
    private static string Visible(string s)
    {
        var sb = new StringBuilder(s.Length);
        foreach (var r in s.EnumerateRunes())
        {
            if (Rune.GetUnicodeCategory(r) == UnicodeCategory.Format || Rune.IsControl(r))
            {
                sb.Append("<U+").Append(r.Value.ToString("X4", CultureInfo.InvariantCulture)).Append('>');
            }
            else
            {
                sb.Append(r.ToString());
            }
        }
        return sb.ToString();
    }

    private static string? Note(string raw, Action<string, Func<Strings, string>> report)
    {
        var note = raw.Trim().Normalize(NormalizationForm.FormC);
        if (note.Length == 0)
        {
            return null;
        }
        if (note.Length > MaxNoteLength)
        {
            report($"note longer than {MaxNoteLength} characters; the rest is cut off", t => t.UrlsNoteCut(MaxNoteLength));
            note = Cut(note, MaxNoteLength).TrimEnd();
        }
        return note;
    }

    /// <summary>Tags for the tags column: alphabetical, separated by ';'.</summary>
    public static string? JoinTags(IReadOnlyList<string>? tags) =>
        tags is { Count: > 0 }
            ? string.Join(TagSeparator, tags.OrderBy(t => t, StringComparer.OrdinalIgnoreCase).ThenBy(t => t, StringComparer.Ordinal))
            : null;

    /// <summary>A caption on one line (line breaks become spaces), cut to <paramref name="max"/> characters plus "\u2026".</summary>
    public static string? ShortCaption(string? caption, int max = IndexCaptionLength)
    {
        if (string.IsNullOrEmpty(caption))
        {
            return null;
        }
        var one = caption.Replace("\r\n", " ").Replace('\r', ' ').Replace('\n', ' ').Replace('\u2028', ' ').Replace('\u2029', ' ');
        return one.Length <= max ? one : Cut(one, max) + "\u2026";
    }

    /// <summary>The first <paramref name="max"/> characters, never splitting a surrogate pair.</summary>
    private static string Cut(string s, int max)
    {
        if (s.Length <= max)
        {
            return s;
        }
        var n = char.IsHighSurrogate(s[max - 1]) ? max - 1 : max;
        return s[..n];
    }

    /// <summary>Text compared by search: NFKC (full-width letters and digits become normal ones).</summary>
    public static string Fold(string s) => s.Normalize(NormalizationForm.FormKC);

    /// <summary>Case-insensitive partial match after NFKC.</summary>
    public static bool Contains(string? haystack, string foldedNeedle) =>
        !string.IsNullOrEmpty(haystack) && Fold(haystack).Contains(foldedNeedle, StringComparison.OrdinalIgnoreCase);

    /// <summary>
    /// A cell that Excel would run as a formula (starts with = + - @ or a tab/CR) gets a leading
    /// apostrophe. Used for text that comes from users or from the post itself.
    /// </summary>
    public static string? SafeCell(string? value) =>
        value is { Length: > 0 } && value[0] is '=' or '+' or '-' or '@' or '\t' or '\r' ? "'" + value : value;

    /// <summary>Undoes SafeCell when reading a cell back.</summary>
    public static string? UnsafeCell(string? value) =>
        value is { Length: > 1 } && value[0] == '\'' && value[1] is '=' or '+' or '-' or '@' or '\t' or '\r' ? value[1..] : value;
}
