using System.Globalization;
using System.Text.RegularExpressions;

namespace ThreadsMonitor.Core;

/// <summary>A polling band relative to publish time: [FromHour, ToHour) every IntervalMinutes.</summary>
public sealed record PollBand(int FromHour, int ToHour, int IntervalMinutes);

/// <summary>
/// Decides when a tracked post should be fetched next.
///
/// Poll times sit on a grid anchored at each band's start (publish + FromHour), so a late run
/// does not drift the schedule. The result never exceeds trackUntil (publish + TrackHours), and
/// the grid always ends with a poll exactly at trackUntil. Time not covered by a band is not
/// polled: before a band (or in a gap) the next poll is that band's start; after the last band it
/// is trackUntil.
/// </summary>
public sealed class PollPlanner(int trackHours, IReadOnlyList<PollBand> bands)
{
    /// <summary>Every hour from publish until the end of the 72-hour window.</summary>
    public static readonly IReadOnlyList<PollBand> DefaultBands = [new(0, 72, 60)];

    public const int MinIntervalMinutes = 5;

    private static readonly Regex BandPattern =
        new(@"^\s*([0-9]{1,6})\s*-\s*([0-9]{1,6})\s*:\s*([0-9]{1,6})\s*$", RegexOptions.CultureInvariant);

    /// <summary>
    /// Parses "from-to:minutes,from-to:minutes,..." (same syntax as the web version's
    /// THREADS_POLL_BANDS). Hours are non-negative integers, to &gt; from, bands are in order and do
    /// not overlap (gaps are allowed), minutes &gt;= MinIntervalMinutes. Null when invalid.
    /// </summary>
    public static IReadOnlyList<PollBand>? ParseBands(string spec)
    {
        var bands = new List<PollBand>();
        var prevTo = 0;
        foreach (var part in spec.Split(','))
        {
            var m = BandPattern.Match(part);
            if (!m.Success)
            {
                return null;
            }
            // [0-9] only: \d would also match full-width and other scripts' digits, which int
            // parsing does not accept. Anything that does not parse is simply invalid (null).
            if (!TryInt(m.Groups[1].Value, out var from) || !TryInt(m.Groups[2].Value, out var to)
                || !TryInt(m.Groups[3].Value, out var minutes))
            {
                return null;
            }
            if (to <= from || from < prevTo || minutes < MinIntervalMinutes)
            {
                return null;
            }
            bands.Add(new PollBand(from, to, minutes));
            prevTo = to;
        }
        return bands;
    }

    private static bool TryInt(string s, out int value) =>
        int.TryParse(s, NumberStyles.None, CultureInfo.InvariantCulture, out value);

    public IReadOnlyList<PollBand> Bands { get; } = bands;

    public PollPlanner(int trackHours = 72) : this(trackHours, DefaultBands)
    {
    }

    public int TrackHours { get; } = trackHours;

    public DateTimeOffset TrackUntil(DateTimeOffset publishedAt) => publishedAt.AddHours(TrackHours);

    public DateTimeOffset NextPollAt(DateTimeOffset publishedAt, DateTimeOffset now)
    {
        var trackUntil = TrackUntil(publishedAt);
        if (now >= trackUntil)
        {
            return trackUntil;
        }

        // Whole seconds since publish; a publish time in the future (clock skew) counts as "just published".
        var elapsed = Math.Max(0, now.ToUnixTimeSeconds() - publishedAt.ToUnixTimeSeconds());

        foreach (var band in Bands)
        {
            long from = band.FromHour * 3600L;
            long to = band.ToHour * 3600L;
            long interval = Math.Max(1, band.IntervalMinutes) * 60L;

            if (elapsed < from)
            {
                // Before the first band, or in a gap between two bands: nothing is fetched until
                // this band starts. (The first fetch when a post is added happens regardless.)
                var start = publishedAt.AddSeconds(from);
                return start > trackUntil ? trackUntil : start;
            }
            if (elapsed < to)
            {
                var next = from + interval * ((elapsed - from) / interval + 1);
                next = Math.Min(next, to); // never skip past the end of this band
                var at = publishedAt.AddSeconds(next);

                return at > trackUntil ? trackUntil : at;
            }
        }

        // After the last band: nothing more until the final poll at trackUntil.
        return trackUntil;
    }
}
