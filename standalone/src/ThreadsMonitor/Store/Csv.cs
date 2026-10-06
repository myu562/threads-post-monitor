using System.Text;

namespace ThreadsMonitor.Store;

/// <summary>
/// RFC 4180 CSV: comma separated, CRLF line ends, fields quoted when needed.
/// Reading also accepts files that Excel saved with ';' or tab as the separator (regional settings):
/// the separator is detected from the header line.
/// </summary>
public static class Csv
{
    public const char Bom = '\uFEFF';

    public static string Field(string? value, char delimiter = ',')
    {
        if (string.IsNullOrEmpty(value))
        {
            return "";
        }
        if (value.IndexOfAny([delimiter, '"', '\r', '\n']) >= 0)
        {
            return "\"" + value.Replace("\"", "\"\"") + "\"";
        }

        return value;
    }

    public static string Line(IEnumerable<string?> fields, char delimiter = ',') =>
        string.Join(delimiter, fields.Select(f => Field(f, delimiter))) + "\r\n";

    /// <summary>
    /// The separator used by the first line: whichever of ',', ';' and tab occurs most often outside
    /// quotes (',' when none does).
    /// </summary>
    public static char DetectDelimiter(string text)
    {
        int comma = 0, semicolon = 0, tab = 0;
        var inQuotes = false;
        foreach (var c in text)
        {
            if (c == '"')
            {
                inQuotes = !inQuotes;
                continue;
            }
            if (inQuotes)
            {
                continue;
            }
            if (c == '\n')
            {
                break;
            }
            switch (c)
            {
                case ',':
                    comma++;
                    break;
                case ';':
                    semicolon++;
                    break;
                case '\t':
                    tab++;
                    break;
            }
        }

        if (semicolon > comma && semicolon >= tab)
        {
            return ';';
        }
        return tab > comma && tab > semicolon ? '\t' : ',';
    }

    public static List<List<string>> Parse(string text) => Parse(text, DetectDelimiter(StripBom(text)));

    public static List<List<string>> Parse(string text, char delimiter)
    {
        var rows = new List<List<string>>();
        text = StripBom(text);

        var row = new List<string>();
        var field = new StringBuilder();
        var inQuotes = false;
        var rowHasContent = false;

        for (var i = 0; i < text.Length; i++)
        {
            var c = text[i];
            if (inQuotes)
            {
                if (c == '"')
                {
                    if (i + 1 < text.Length && text[i + 1] == '"')
                    {
                        field.Append('"');
                        i++;
                    }
                    else
                    {
                        inQuotes = false;
                    }
                }
                else
                {
                    field.Append(c);
                }
                continue;
            }

            if (c == delimiter)
            {
                row.Add(field.ToString());
                field.Clear();
                rowHasContent = true;
                continue;
            }

            switch (c)
            {
                case '"':
                    inQuotes = true;
                    rowHasContent = true;
                    break;
                case '\r':
                    break;
                case '\n':
                    row.Add(field.ToString());
                    field.Clear();
                    if (rowHasContent || row.Count > 1 || row[0].Length > 0)
                    {
                        rows.Add(row);
                    }
                    row = [];
                    rowHasContent = false;
                    break;
                default:
                    field.Append(c);
                    rowHasContent = true;
                    break;
            }
        }

        if (rowHasContent || field.Length > 0)
        {
            row.Add(field.ToString());
            rows.Add(row);
        }

        return rows;
    }

    /// <summary>Rows as dictionaries keyed by the header row (columns matched by name, any separator).</summary>
    public static List<Dictionary<string, string>> ParseWithHeader(string text) => Read(text).Rows;

    /// <summary>A parsed file: separator, header names (trimmed) and the rows keyed by header name.</summary>
    public sealed record Table(char Delimiter, List<string> Header, List<Dictionary<string, string>> Rows, List<int> FieldCounts);

    public static Table Read(string text)
    {
        var delimiter = DetectDelimiter(StripBom(text));
        var rows = Parse(text, delimiter);
        var result = new List<Dictionary<string, string>>();
        var counts = new List<int>();
        if (rows.Count == 0)
        {
            return new Table(delimiter, [], result, counts);
        }

        var header = rows[0].Select(h => h.Trim()).ToList();
        foreach (var r in rows.Skip(1))
        {
            var d = new Dictionary<string, string>(StringComparer.Ordinal);
            for (var i = 0; i < header.Count; i++)
            {
                d.TryAdd(header[i], i < r.Count ? r[i] : "");
            }
            result.Add(d);
            counts.Add(r.Count);
        }

        return new Table(delimiter, header, result, counts);
    }

    private static string StripBom(string text) => text.Length > 0 && text[0] == Bom ? text[1..] : text;
}
