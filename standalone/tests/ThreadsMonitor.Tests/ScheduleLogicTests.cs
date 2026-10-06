using ThreadsMonitor.Core;

namespace ThreadsMonitor.Tests;

public class PollPlannerTests
{
    private static readonly DateTimeOffset Pub = new(2026, 10, 3, 4, 0, 0, TimeSpan.Zero);

    // The band-boundary tests use the hourly-then-3-hourly schedule (a band change at 24h);
    // the default (hourly for 72 hours) has its own tests below.
    private static readonly PollPlanner TwoBands = new(72, [new PollBand(0, 24, 60), new PollBand(24, 72, 180)]);

    private static int Next(int minutesAfterPublish, PollPlanner? planner = null)
    {
        var at = (planner ?? TwoBands).NextPollAt(Pub, Pub.AddMinutes(minutesAfterPublish));
        return (int)(at - Pub).TotalMinutes;
    }

    [Theory]
    [InlineData(0, 60)]                 // at publish
    [InlineData(30, 60)]                // mid first slot
    [InlineData(60, 120)]               // exactly on grid point
    [InlineData(61, 120)]               // just after grid point
    [InlineData(23 * 60 + 30, 24 * 60)] // just before band boundary
    [InlineData(24 * 60 - 1, 24 * 60)]
    [InlineData(24 * 60, 27 * 60)]      // exactly at 24h band boundary
    [InlineData(25 * 60, 27 * 60)]      // inside second band
    [InlineData(27 * 60, 30 * 60)]
    [InlineData(69 * 60, 72 * 60)]      // last grid point before the end
    [InlineData(72 * 60 - 1, 72 * 60)]
    [InlineData(72 * 60, 72 * 60)]      // exactly 72h
    [InlineData(100 * 60, 72 * 60)]     // past 72h
    public void Band_boundaries(int now, int expected) => Assert.Equal(expected, Next(now));

    [Fact]
    public void Now_before_publish_is_treated_as_just_published() =>
        Assert.Equal(Pub.AddMinutes(60), new PollPlanner().NextPollAt(Pub, Pub.AddMinutes(-10)));

    private static List<DateTimeOffset> Walk(PollPlanner planner)
    {
        var trackUntil = planner.TrackUntil(Pub);
        var now = Pub;
        var polls = new List<DateTimeOffset>();
        for (var guard = 0; guard < 1000; guard++)
        {
            var next = planner.NextPollAt(Pub, now);
            Assert.True(next > now || next == trackUntil);
            Assert.True(next <= trackUntil);
            polls.Add(next);
            if (next == trackUntil)
            {
                break;
            }
            now = next;
        }
        return polls;
    }

    [Fact]
    public void Walking_the_schedule_ends_exactly_at_track_until()
    {
        var polls = Walk(TwoBands);
        Assert.Equal(Pub.AddHours(72), polls[^1]);
        Assert.Equal(24 + 16, polls.Count); // 24 hourly + 16 three-hourly, the last one at 72h
    }

    [Fact]
    public void Default_schedule_is_72_hourly_polls_ending_at_72h()
    {
        Assert.Equal([new PollBand(0, 72, 60)], PollPlanner.DefaultBands);
        var polls = Walk(new PollPlanner());
        Assert.Equal(72, polls.Count);
        for (var i = 0; i < polls.Count; i++)
        {
            Assert.Equal(Pub.AddHours(i + 1), polls[i]);
        }
        Assert.Equal(19 * 60, Next(18 * 60, new PollPlanner())); // no gap after 24h any more
    }

    [Fact]
    public void Tracker_uses_the_configured_bands()
    {
        Assert.Equal(PollPlanner.DefaultBands, new Tracker(new TrackerOptions()).Planner.Bands);
        var bands = PollPlanner.ParseBands("0-24:60,24-72:180")!;
        Assert.Equal(bands, new Tracker(new TrackerOptions(PollBands: bands)).Planner.Bands);
    }

    [Fact]
    public void Late_run_does_not_drift_the_grid() => Assert.Equal(180, Next(124));

    [Fact]
    public void Result_capped_at_track_until_when_bands_exceed_it() =>
        Assert.Equal(48 * 60, Next(46 * 60, new PollPlanner(48, TwoBands.Bands)));

    [Fact]
    public void Uncovered_time_goes_straight_to_track_until()
    {
        var planner = new PollPlanner(72, [new PollBand(0, 24, 60)]);
        Assert.Equal(72 * 60, Next(30 * 60, planner));
        Assert.Equal(24 * 60, Next(23 * 60 + 1, planner));
    }

    [Fact]
    public void Track_until_is_publish_plus_track_hours() =>
        Assert.Equal(Pub.AddHours(72), new PollPlanner().TrackUntil(Pub));
}

/// <summary>Same cases as tests/Unit/Threads/PollPlannerTest.php (specs) in the web version.</summary>
public class PollBandsSpecTests
{
    public static TheoryData<string, int[][]?> Specs => new()
    {
        { "0-24:60,24-72:180", [[0, 24, 60], [24, 72, 180]] },
        { "0-72:60", [[0, 72, 60]] },
        { " 0 - 24 : 60 , 24 - 72 : 180 ", [[0, 24, 60], [24, 72, 180]] },
        { "0-24:60,48-72:120", [[0, 24, 60], [48, 72, 120]] },
        { "0-72:5", [[0, 72, 5]] },
        { "abc", null },
        { "0-24", null },
        { "0-24:60,", null },
        { "0-24:60;24-72:180", null },
        { "24-0:60", null },
        { "5-5:60", null },
        { "-1-24:60", null },
        { "0.5-24:60", null },
        { "0-24:4", null },
        { "0-24:0", null },
        { "0-24:60,12-72:180", null },
        { "24-72:180,0-24:60", null },
        { "0-24:60,24-72:x", null },
    };

    [Theory]
    [MemberData(nameof(Specs))]
    public void Parse_bands(string spec, int[][]? expected)
    {
        var parsed = PollPlanner.ParseBands(spec);
        Assert.Equal(expected?.Select(b => new PollBand(b[0], b[1], b[2])).ToList(), parsed?.ToList());
    }

    [Fact]
    public void Settings_ini_poll_bands_valid_is_used()
    {
        var warnings = new List<string>();
        var s = Settings.Parse("poll_bands = 0-24:60,24-72:180", warnings.Add);
        Assert.Equal([new PollBand(0, 24, 60), new PollBand(24, 72, 180)], s.Tracker.PollBands);
        Assert.Empty(warnings);
    }

    [Theory]
    [InlineData("poll_bands = 0-24:60,12-72:180")]
    [InlineData("poll_bands = 0-24:4")]
    [InlineData("poll_bands = every hour")]
    public void Settings_ini_poll_bands_invalid_warns_and_uses_default(string line)
    {
        var warnings = new List<string>();
        var s = Settings.Parse(line, warnings.Add);
        Assert.Null(s.Tracker.PollBands);
        Assert.Equal(PollPlanner.DefaultBands, new Tracker(s.Tracker).Planner.Bands);
        Assert.Single(warnings);
        Assert.Contains("poll_bands", warnings[0]);
    }

    [Fact]
    public void Settings_ini_poll_bands_empty_uses_default_silently()
    {
        var warnings = new List<string>();
        Assert.Null(Settings.Parse("poll_bands =", warnings.Add).Tracker.PollBands);
        Assert.Null(Settings.Parse(null).Tracker.PollBands);
        Assert.Empty(warnings);
    }
}

public class TrackerTests
{
    private static readonly DateTimeOffset Pub = new(2026, 10, 3, 4, 0, 0, TimeSpan.Zero);
    private static readonly Tracker Tracker = new(new TrackerOptions());

    private static PostMeta Meta() => new()
    {
        Shortcode = Fixtures.Code,
        PublishedAt = Pub,
        PublishedAtSource = PublishedSource.Page,
    };

    private static Snapshot Ok(double hours, long views = 100) =>
        new(Pub.AddHours(hours), Fixtures.Code, "example_user", hours, FetchStatus.Ok, views, 1, 2, 3, 4, null, null);

    private static Snapshot Fail(double hours, string status = FetchStatus.Blocked) =>
        new(Pub.AddHours(hours), Fixtures.Code, null, hours, status, null, null, null, null, null, null, "boom");

    [Fact]
    public void Never_fetched_is_due_now()
    {
        var s = Tracker.Evaluate(Meta(), []);
        Assert.Equal(PostStatus.Tracking, s.Status);
        Assert.True(Tracker.IsDue(s, Pub));
    }

    [Fact]
    public void Next_due_is_next_grid_point_after_last_success()
    {
        var s = Tracker.Evaluate(Meta(), [Ok(2 + 4 / 60.0)]);
        Assert.Equal(Pub.AddHours(3), s.NextDue);
        Assert.False(Tracker.IsDue(s, Pub.AddHours(2.9)));
        Assert.True(Tracker.IsDue(s, Pub.AddHours(3)));
    }

    [Fact]
    public void Manual_fetch_between_grid_points_does_not_move_the_grid()
    {
        // Scheduled at 2h, manual update at 2h30: the next scheduled fetch is still 3h.
        var s = Tracker.Evaluate(Meta(), [Ok(2), Ok(2.5)]);
        Assert.Equal(Pub.AddHours(3), s.NextDue);
    }

    [Fact]
    public void Ok_at_72h_is_final_and_done()
    {
        var s = Tracker.Evaluate(Meta(), [Ok(1), Ok(69, 500), Ok(72, 900)]);
        Assert.Equal(PostStatus.Done, s.Status);
        Assert.Equal(900, s.Final!.Views);
        Assert.Equal(Pub.AddHours(72), s.Final.CapturedAt);
        Assert.False(s.LateFinal);
        Assert.False(s.AddedAfterWindow);
        Assert.Null(s.NextDue);
    }

    [Fact]
    public void Final_delayed_by_computer_off_is_flagged_late_with_real_time()
    {
        var s = Tracker.Evaluate(Meta(), [Ok(69, 500), Ok(80, 950)]);
        Assert.Equal(PostStatus.Done, s.Status);
        Assert.True(s.LateFinal);
        Assert.Equal(Pub.AddHours(80), s.Final!.CapturedAt); // never pretends to be 72h
        Assert.Equal(950, s.Final.Views);
    }

    [Fact]
    public void Final_a_few_minutes_after_72h_is_not_late()
    {
        var s = Tracker.Evaluate(Meta(), [Ok(69), Ok(72 + 10 / 60.0)]);
        Assert.False(s.LateFinal);
    }

    [Fact]
    public void First_success_after_window_is_final_and_added_after_window()
    {
        var s = Tracker.Evaluate(Meta(), [Ok(100, 777)]);
        Assert.Equal(PostStatus.Done, s.Status);
        Assert.True(s.AddedAfterWindow);
        Assert.Equal(777, s.Final!.Views);
    }

    [Fact]
    public void Failure_retries_after_15_minutes()
    {
        var s = Tracker.Evaluate(Meta(), [Ok(1), Fail(2)]);
        Assert.Equal(PostStatus.Tracking, s.Status);
        Assert.Equal(1, s.ConsecutiveFailures);
        Assert.Equal(Pub.AddHours(2).AddMinutes(15), s.NextDue);
        Assert.StartsWith("[blocked]", s.LastError);
    }

    [Fact]
    public void Retry_is_capped_at_track_until()
    {
        var s = Tracker.Evaluate(Meta(), [Ok(69), Fail(72 - 5 / 60.0)]);
        Assert.Equal(Pub.AddHours(72), s.NextDue);
    }

    [Fact]
    public void Success_clears_failures()
    {
        var s = Tracker.Evaluate(Meta(), [Fail(1), Fail(1.25), Ok(1.5)]);
        Assert.Equal(0, s.ConsecutiveFailures);
        Assert.Null(s.LastError);
    }

    [Fact]
    public void Five_consecutive_failures_is_failed_with_final_from_last_success()
    {
        var s = Tracker.Evaluate(Meta(), [Ok(1, 42), Fail(2), Fail(2.25), Fail(2.5), Fail(2.75), Fail(3)]);
        Assert.Equal(PostStatus.Failed, s.Status);
        Assert.Equal(42, s.Final!.Views);
        Assert.Equal(Pub.AddHours(1), s.Final.CapturedAt);
        Assert.Null(s.NextDue);
    }

    [Fact]
    public void Gone_stops_at_once_and_keeps_last_success_as_final()
    {
        var s = Tracker.Evaluate(Meta(), [Ok(1, 42), Fail(2, FetchStatus.Gone), Ok(3, 99)]);
        Assert.Equal(PostStatus.Gone, s.Status);
        Assert.Equal(42, s.Final!.Views);
        Assert.Null(s.NextDue);
    }

    [Fact]
    public void Gone_without_success_has_no_final()
    {
        var s = Tracker.Evaluate(Meta(), [Fail(1, FetchStatus.Gone)]);
        Assert.Equal(PostStatus.Gone, s.Status);
        Assert.Null(s.Final);
    }

    [Fact]
    public void Success_without_any_publish_time_counts_as_failure()
    {
        var meta = Meta();
        meta.PublishedAt = null;
        var s = Tracker.Evaluate(meta, [Ok(1)]);
        Assert.Equal(PostStatus.Tracking, s.Status);
        Assert.Equal(1, s.ConsecutiveFailures);
        Assert.StartsWith("[no_publish_time]", s.LastError);
    }
}
