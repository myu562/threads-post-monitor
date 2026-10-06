using System.Globalization;
using System.Text;

namespace ThreadsMonitor.Store;

/// <summary>
/// Folder names that work on Windows, macOS, Linux and OneDrive.
///  - \ / : * ? " &lt; &gt; | and control characters become _
///  - leading/trailing whitespace and trailing dots are removed
///  - leading dots become _ (a folder starting with a dot is hidden and would never be found again)
///  - Windows reserved names (CON, PRN, AUX, NUL, COM1-9, LPT1-9, also with an extension) get a leading _
///  - Unicode NFC
/// </summary>
public static class Naming
{
    public const int MaxGroupLength = 40;
    public const int MaxPathLength = 240;
    public const string UnknownDatePrefix = "0000-00-00_";

    private static readonly HashSet<string> Reserved = new(StringComparer.OrdinalIgnoreCase)
    {
        "CON", "PRN", "AUX", "NUL",
        "COM1", "COM2", "COM3", "COM4", "COM5", "COM6", "COM7", "COM8", "COM9",
        "LPT1", "LPT2", "LPT3", "LPT4", "LPT5", "LPT6", "LPT7", "LPT8", "LPT9",
    };

    public static string Sanitize(string name, int? maxLength = null)
    {
        var s = name.Normalize(NormalizationForm.FormC);
        var sb = new StringBuilder(s.Length);
        foreach (var c in s)
        {
            sb.Append(c is '\\' or '/' or ':' or '*' or '?' or '"' or '<' or '>' or '|' || char.IsControl(c) ? '_' : c);
        }
        s = Clean(sb.ToString());

        if (maxLength is { } max && s.Length > max)
        {
            var info = new StringInfo(s);
            var take = 0;
            var length = 0;
            while (take < info.LengthInTextElements)
            {
                var next = info.SubstringByTextElements(take, 1).Length;
                if (length + next > max)
                {
                    break;
                }
                length += next;
                take++;
            }
            s = Clean(info.SubstringByTextElements(0, take));
        }

        if (s.Length == 0)
        {
            return "_";
        }

        var dots = 0;
        while (dots < s.Length && s[dots] == '.')
        {
            dots++;
        }
        if (dots > 0)
        {
            s = new string('_', dots) + s[dots..];
        }

        var dot = s.IndexOf('.');
        var stem = (dot < 0 ? s : s[..dot]).TrimEnd();
        return Reserved.Contains(stem) ? "_" + s : s;
    }

    private static string Clean(string s)
    {
        string before;
        do
        {
            before = s;
            s = s.Trim().TrimEnd('.');
        }
        while (s != before);
        return s;
    }

    /// <summary>{yyyy-MM-dd}_{HH}h_@{username}_{shortcode} in local time; 0000-00-00_00h_ when the time is unknown.</summary>
    public static string PostFolder(DateTimeOffset? publishedLocal, string? username, string shortcode)
    {
        var stamp = publishedLocal is { } p
            ? p.ToString("yyyy-MM-dd'_'HH'h'", CultureInfo.InvariantCulture)
            : "0000-00-00_00h";
        var user = string.IsNullOrEmpty(username) ? "" : "@" + Sanitize(username) + "_";
        return Sanitize(stamp + "_" + user + shortcode);
    }

    /// <summary>Short form used when the full path would be too long: {yyyy-MM-dd}_{shortcode}.</summary>
    public static string ShortPostFolder(DateTimeOffset? publishedLocal, string shortcode)
    {
        var date = publishedLocal is { } p ? p.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture) : "0000-00-00";
        return Sanitize(date + "_" + shortcode);
    }

    public static bool IsArchiveName(string folderName)
    {
        var n = folderName.Normalize(NormalizationForm.FormC).Trim();
        return n == "封存" || n.Equals("archive", StringComparison.OrdinalIgnoreCase);
    }

    private static readonly System.Text.RegularExpressions.Regex NumberedCopyRe =
        new(@"\s\([0-9]+\)(\.[^.\s]*)?$", System.Text.RegularExpressions.RegexOptions.CultureInvariant);

    /// <summary>
    /// True for names that sync programs give to conflict copies: "name (1).csv", "name-HOSTNAME"
    /// (OneDrive appends the computer name), "name (conflicted copy ...)" (Dropbox).
    /// </summary>
    public static bool IsConflictName(string name, IEnumerable<string> hostNames)
    {
        if (name.Contains("conflicted copy", StringComparison.OrdinalIgnoreCase) || NumberedCopyRe.IsMatch(name))
        {
            return true;
        }

        return hostNames.Any(h => h.Length > 0 && name.Contains("-" + h, StringComparison.OrdinalIgnoreCase));
    }

    /// <summary>Appends _2, _3 ... until the name does not collide (case-insensitively) with any existing name.</summary>
    public static string Unique(string name, IEnumerable<string> existing)
    {
        var taken = new HashSet<string>(existing.Select(e => e.Normalize(NormalizationForm.FormC)), StringComparer.OrdinalIgnoreCase);
        if (!taken.Contains(name))
        {
            return name;
        }
        for (var n = 2; ; n++)
        {
            var candidate = name + "_" + n.ToString(CultureInfo.InvariantCulture);
            if (!taken.Contains(candidate))
            {
                return candidate;
            }
        }
    }
}
