namespace ThreadsMonitor.Core;

public static class PostStatus
{
    public const string Tracking = "tracking";
    public const string Done = "done";
    public const string Gone = "gone";
    public const string Failed = "failed";
}

public static class PublishedSource
{
    public const string Page = "page";
    public const string Shortcode = "shortcode";
}

/// <summary>One fetch attempt (one line of snapshots.csv). Failed attempts have no metrics.</summary>
public sealed record Snapshot(
    DateTimeOffset CapturedAt,
    string Shortcode,
    string? Username,
    double? HoursSincePublish,
    string Status,
    long? Views,
    long? Likes,
    long? Replies,
    long? Reposts,
    long? Quotes,
    long? Shares,
    string? Error,
    int? TrackHours = null,
    string? Caption = null)
{
    public bool IsOk => Status == FetchStatus.Ok;
}

/// <summary>What is known about a post besides its fetch results (stored with every snapshot).</summary>
public sealed class PostMeta
{
    public required string Shortcode { get; init; }
    public string? Username { get; set; }
    public DateTimeOffset? PublishedAt { get; set; }
    public string? PublishedAtSource { get; set; }
    public bool CountsHidden { get; set; }

    /// <summary>The post text (caption.text), stored once: in the first snapshot row that had it.</summary>
    public string? Caption { get; set; }
}

public sealed record TrackerOptions(
    int TrackHours = 72,
    int RetryMinutes = 15,
    int MaxConsecutiveFailures = 5,
    int LateFinalToleranceMinutes = 20,
    IReadOnlyList<PollBand>? PollBands = null);

/// <summary>The state of one post, derived from its metadata and its snapshots.</summary>
public sealed record PostState(
    string Status,
    Snapshot? LastOk,
    Snapshot? Final,
    DateTimeOffset? TrackUntil,
    bool LateFinal,
    bool AddedAfterWindow,
    int ConsecutiveFailures,
    DateTimeOffset? LastAttempt,
    string? LastError,
    DateTimeOffset? NextDue);

/// <summary>
/// The tracking state machine. It is a pure function of the post metadata and its snapshots
/// (in capture order), so the CSV files are the only state.
///
///  - ok: resets the failure counter. The first ok snapshot captured at or after trackUntil is
///    the final reading (done). If that is also the first ok snapshot at all, the post was
///    added after its window closed (added_after_window). If it was captured more than the
///    tolerance after trackUntil (computer off or asleep), late_final is set; its real time is
///    always kept.
///  - gone: stop at once; final = last ok snapshot, at its own time.
///  - network_error (no connection on this computer): not counted as a failure, just retried after
///    RetryMinutes. A laptop that is offline for a while must not end up with failed posts.
///  - any other failure (blocked, http_error, parse_failed, ...): retry after RetryMinutes (capped
///    at trackUntil while the window is open). After MaxConsecutiveFailures in a row:
///      - before trackUntil: failed, final = last ok snapshot;
///      - at or after trackUntil: done with final = last ok snapshot and late_final set
///        (failed only when there is no ok snapshot at all).
///  - an ok snapshot while the publish time is unknown counts as a failure (no_publish_time).
///  - the window length is the track_hours stored with the post's first snapshot, so changing
///    settings.ini later does not reopen or shorten posts that already started.
///  - snapshots dated more than 24 hours after "now" (computer clock was wrong) are ignored and
///    reported in LastError.
/// </summary>
public sealed class Tracker(TrackerOptions options)
{
    public TrackerOptions Options { get; } = options;

    public PollPlanner Planner { get; } = new(options.TrackHours, options.PollBands ?? PollPlanner.DefaultBands);

    public static readonly TimeSpan FutureTolerance = TimeSpan.FromHours(24);

    public PostState Evaluate(PostMeta meta, IEnumerable<Snapshot> snapshots, DateTimeOffset? now = null)
    {
        var list = snapshots.ToList();
        var futureCount = 0;
        if (now is { } n)
        {
            var kept = list.Where(s => s.CapturedAt - n <= FutureTolerance).ToList();
            futureCount = list.Count - kept.Count;
            list = kept;
        }

        var trackHours = list.Count > 0 && list[0].TrackHours is { } th and > 0 ? th : Options.TrackHours;
        var planner = trackHours == Planner.TrackHours ? Planner : new PollPlanner(trackHours, Planner.Bands);
        DateTimeOffset? trackUntil = meta.PublishedAt is { } pub ? planner.TrackUntil(pub) : null;
        var status = PostStatus.Tracking;
        var retryNeeded = false;
        var fallbackFinal = false;
        Snapshot? lastOk = null;
        Snapshot? final = null;
        var addedAfter = false;
        var failures = 0;
        DateTimeOffset? lastAttempt = null;
        string? lastError = null;

        foreach (var s in list)
        {
            if (status != PostStatus.Tracking)
            {
                break;
            }
            lastAttempt = s.CapturedAt;

            if (s.IsOk && trackUntil.HasValue)
            {
                var isFirst = lastOk == null;
                lastOk = s;
                failures = 0;
                retryNeeded = false;
                lastError = null;
                if (s.CapturedAt >= trackUntil.Value)
                {
                    final = s;
                    status = PostStatus.Done;
                    addedAfter = isFirst;
                }
            }
            else if (s.Status == FetchStatus.Gone)
            {
                status = PostStatus.Gone;
                final = lastOk;
                lastError = Text.OneLine($"[{FetchStatus.Gone}] {s.Error}");
            }
            else if (s.Status == FetchStatus.NetworkError)
            {
                // This computer was offline: retry, but never count it towards "failed".
                retryNeeded = true;
                lastError = Text.OneLine($"[{s.Status}] {s.Error}");
            }
            else
            {
                if (s.IsOk)
                {
                    lastOk = s; // metrics are real even though the 72h window is undefined
                }
                failures++;
                lastError = s.IsOk
                    ? $"[{FetchStatus.NoPublishTime}] Publish time unknown."
                    : Text.OneLine($"[{s.Status}] {s.Error}");
                if (failures >= Options.MaxConsecutiveFailures)
                {
                    if (trackUntil.HasValue && s.CapturedAt >= trackUntil.Value && lastOk is { IsOk: true })
                    {
                        // The window is over and every retry of the final reading failed: keep the
                        // last real numbers as the final ones (like the web version).
                        status = PostStatus.Done;
                        fallbackFinal = true;
                    }
                    else
                    {
                        status = PostStatus.Failed;
                    }
                    final = lastOk;
                }
            }
        }

        var lateFinal = fallbackFinal || (status == PostStatus.Done && final != null && trackUntil.HasValue
            && final.CapturedAt - trackUntil.Value > TimeSpan.FromMinutes(Options.LateFinalToleranceMinutes));

        DateTimeOffset? nextDue = null;
        if (status == PostStatus.Tracking)
        {
            if (lastAttempt == null)
            {
                nextDue = DateTimeOffset.MinValue; // never fetched: due now
            }
            else if (failures > 0 || retryNeeded || meta.PublishedAt == null || lastOk == null)
            {
                var retry = lastAttempt.Value.AddMinutes(Options.RetryMinutes);
                if (trackUntil.HasValue && lastAttempt.Value < trackUntil.Value && retry > trackUntil.Value)
                {
                    retry = trackUntil.Value;
                }
                nextDue = retry;
            }
            else
            {
                nextDue = planner.NextPollAt(meta.PublishedAt.Value, lastOk.CapturedAt);
            }
        }

        if (futureCount > 0)
        {
            lastError = Text.T.FutureSnapshots;
        }

        return new PostState(status, lastOk, final, trackUntil, lateFinal, addedAfter && status == PostStatus.Done,
            failures, lastAttempt, lastError, nextDue);
    }

    public static bool IsDue(PostState state, DateTimeOffset now) =>
        state.Status == PostStatus.Tracking && state.NextDue is { } due && now >= due;
}
