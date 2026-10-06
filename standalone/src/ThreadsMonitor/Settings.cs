using System.Globalization;
using ThreadsMonitor.Core;

namespace ThreadsMonitor;

/// <summary>
/// Optional settings.ini in the work folder ("key = value" lines; # or ; starts a comment,
/// [sections] are ignored). Missing file or missing keys use the defaults. No value can stop the
/// program: anything that is not understood is reported through warn and the default is used.
/// </summary>
public sealed record Settings
{
    public const string DefaultUserAgent = "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)";

    public string UserAgent { get; init; } = DefaultUserAgent;
    public int TimeoutSeconds { get; init; } = 30;
    public double SleepSeconds { get; init; } = 3;
    public TrackerOptions Tracker { get; init; } = new();

    public static Settings Parse(string? text, Action<string>? warn = null)
    {
        var s = new Settings();
        if (string.IsNullOrEmpty(text))
        {
            return s;
        }

        var t = s.Tracker;
        foreach (var raw in text.Split('\n'))
        {
            var line = raw.Trim().TrimStart('\uFEFF');
            if (line.Length == 0 || line[0] is '#' or ';' or '[')
            {
                continue;
            }
            var eq = line.IndexOf('=');
            if (eq <= 0)
            {
                warn?.Invoke("settings.ini: ignored line (expected key = value)" + Hint(line) + ": " + line);
                continue;
            }
            var key = line[..eq].Trim().ToLowerInvariant();
            var value = line[(eq + 1)..].Trim().Trim('"');

            switch (key)
            {
                case "user_agent" when value.Length > 0 && value.All(c => c is >= ' ' and <= '~'):
                    s = s with { UserAgent = value };
                    break;
                case "timeout_seconds" when Int(value, 1, 600) is { } v:
                    s = s with { TimeoutSeconds = v };
                    break;
                case "sleep_seconds" when double.TryParse(value, NumberStyles.Float, CultureInfo.InvariantCulture, out var d) && d >= 0 && d <= 600:
                    s = s with { SleepSeconds = d };
                    break;
                case "track_hours" when Int(value, 1, 24 * 30) is { } v:
                    t = t with { TrackHours = v };
                    break;
                case "retry_minutes" when Int(value, 1, 24 * 60) is { } v:
                    t = t with { RetryMinutes = v };
                    break;
                case "max_consecutive_failures" when Int(value, 1, 1000) is { } v:
                    t = t with { MaxConsecutiveFailures = v };
                    break;
                case "late_final_tolerance_minutes" when Int(value, 0, 24 * 60) is { } v:
                    t = t with { LateFinalToleranceMinutes = v };
                    break;
                case "poll_bands" when value.Length == 0:
                    t = t with { PollBands = null }; // empty: default schedule
                    break;
                case "poll_bands" when PollPlanner.ParseBands(value) is { } bands:
                    t = t with { PollBands = bands };
                    break;
                case "poll_bands":
                    t = t with { PollBands = null };
                    warn?.Invoke("settings.ini: poll_bands is invalid, using the default (every 60 minutes for 72 hours). "
                        + "Expected from-to:minutes,... e.g. 0-24:60,24-72:180 (minutes >= " + PollPlanner.MinIntervalMinutes + ")"
                        + Hint(value) + ": " + line);
                    break;
                default:
                    warn?.Invoke("settings.ini: ignored line (unknown key or invalid value, the default is used)" + Hint(value) + ": " + line);
                    break;
            }
        }

        return s with { Tracker = t };
    }

    /// <summary>A short tip when the text has full-width or other non-ASCII digits or punctuation.</summary>
    private static string Hint(string text)
    {
        if (text.Any(c => char.IsDigit(c) && !char.IsAsciiDigit(c)))
        {
            return " (use half-width digits 0-9)";
        }
        if (text.IndexOfAny(['\uFF1D', '\uFF1A', '\uFF0C', '\uFF0D']) >= 0)
        {
            return " (use half-width = : , -)";
        }
        return "";
    }

    private static int? Int(string v, int min, int max) =>
        int.TryParse(v, NumberStyles.Integer, CultureInfo.InvariantCulture, out var n) && n >= min && n <= max ? n : null;
}
