using System.Text;
using System.Xml.Linq;
using ThreadsMonitor.Core;
using ThreadsMonitor.Store;

namespace ThreadsMonitor.Tests;

/// <summary>FX-D contracts not already covered by V5AttackTests.</summary>
public class FixDTests
{
    private const string Code = Fixtures.Code;
    private const string PostUrl = "https://www.threads.com/@example_user/post/" + Code;
    private static readonly DateTimeOffset Taken = Harness.Start.AddHours(-2);
    private const string Folder = "2026-10-05_06h_@example_user_" + Code;

    private static Harness WithPost(string urls = PostUrl + "\n")
    {
        var h = new Harness();
        h.Urls(urls);
        h.Page(Code, Taken, views: 1234, likes: 56);
        return h;
    }

    // ---------- D2 merge ----------

    [Fact]
    public void D2_interrupted_merge_is_resumed_without_duplicates()
    {
        using var h = WithPost();
        h.Run("run");
        var dir = h.PathOf("Ungrouped", Folder);
        var main = File.ReadAllText(Path.Combine(dir, "snapshots.csv"));
        var existingRow = main.Split("\r\n")[1];
        var newRow = existingRow.Replace("2026-10-05T08:00:00+08:00", "2026-10-05T08:30:00+08:00");
        // A merge that stopped after appending existingRow, before deleting its file.
        File.WriteAllText(Path.Combine(dir, "snapshots.pending-20261005083000.csv.merging"),
            "\uFEFF" + Csv.Line(FolderStore.SnapshotColumns) + existingRow + "\r\n" + newRow + "\r\n");
        h.Time.Advance(TimeSpan.FromMinutes(10));
        h.Run("run");
        var rows = h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv");
        Assert.Equal(["2026-10-05T08:00:00+08:00", "2026-10-05T08:30:00+08:00"], rows.Select(r => r["captured_at_local"]));
        Assert.Empty(Directory.GetFiles(dir, "snapshots.pending-*"));
    }

    [Fact]
    public void D2_merge_while_still_locked_keeps_the_merging_file_and_its_rows_count()
    {
        using var h = WithPost();
        h.Run("run");
        h.Files.Locked.Add("snapshots.csv");
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run"); // -> pending
        h.Time.Advance(TimeSpan.FromMinutes(10));
        h.Run("run"); // merge attempt while still locked
        var dir = h.PathOf("Ungrouped", Folder);
        Assert.Single(Directory.GetFiles(dir, "snapshots.pending-*.csv.merging"));
        Assert.Equal("3.00", Assert.Single(h.Index())["hours_since_publish"]); // rows in the .merging file still count
        h.Files.Locked.Clear();
        h.Time.Advance(TimeSpan.FromMinutes(10));
        h.Run("run");
        Assert.Equal(2, h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv").Count);
        Assert.Empty(Directory.GetFiles(dir, "snapshots.pending-*"));
    }

    [Fact]
    public void D2_pending_with_rows_of_another_post_is_bad()
    {
        using var h = WithPost();
        h.Run("run");
        var dir = h.PathOf("Ungrouped", Folder);
        var row = File.ReadAllText(Path.Combine(dir, "snapshots.csv")).Split("\r\n")[1].Replace(Code, "DOther0000");
        File.WriteAllText(Path.Combine(dir, "snapshots.pending-20261005083000.csv"), Csv.Line(FolderStore.SnapshotColumns) + row + "\r\n");
        h.Run("run");
        Assert.True(File.Exists(Path.Combine(dir, "snapshots.pending-20261005083000.bad.csv")));
        Assert.Single(h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv"));
    }

    // ---------- D3 header / unreadable ----------

    [Fact]
    public void D3_extra_column_added_by_user_is_left_empty_in_new_rows()
    {
        using var h = WithPost();
        h.Run("run");
        var path = h.PathOf("Ungrouped", Folder, "snapshots.csv");
        var rows = Csv.Parse(File.ReadAllText(path));
        rows[0].Insert(0, "my_notes");
        rows[1].Insert(0, "great post");
        File.WriteAllText(path, string.Concat(rows.Select(r => Csv.Line(r))), new UTF8Encoding(true));
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        var data = h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv");
        Assert.Equal(["great post", ""], data.Select(r => r["my_notes"]));
        Assert.All(data, r => Assert.Equal(Code, r["shortcode"]));
        Assert.Equal("1234", data[1]["views"]);
    }

    [Fact]
    public void D3_unreadable_snapshots_folder_is_read_only_and_not_refetched()
    {
        using var h = WithPost();
        h.Run("run");
        var path = h.PathOf("Ungrouped", Folder, "snapshots.csv");
        File.WriteAllText(path, "something,else\r\n1,2\r\n"); // the shortcode column is gone
        h.Requests.Clear();
        for (var i = 0; i < 2; i++)
        {
            h.Time.Advance(TimeSpan.FromHours(1));
            Assert.Equal(0, h.Run("run"));
        }
        Assert.Empty(h.Requests);
        Assert.Equal([$"Ungrouped/{Folder}"], h.PostFolders());
        Assert.Equal("something,else\r\n1,2\r\n", File.ReadAllText(path));
        var idx = Assert.Single(h.Index());
        Assert.Contains("Cannot read snapshots.csv", idx["last_error"]);
        Assert.Equal("", idx["next_due_local"]);
        Assert.Contains("Cannot read", h.Text);
    }

    [Fact]
    public void D3_unreadable_folder_with_underscore_shortcode_still_blocks_that_post()
    {
        const string code = "CFake_Old01";
        using var h = new Harness();
        h.Urls("https://www.threads.com/t/" + code + "\n");
        h.Page(code, Taken);
        Directory.CreateDirectory(h.PathOf("Ungrouped", "2026-10-05_06h_@some_user_" + code));
        File.WriteAllText(h.PathOf("Ungrouped", "2026-10-05_06h_@some_user_" + code, "snapshots.csv"), "x;y\r\n");
        h.Run("run");
        Assert.Empty(h.Requests);
        Assert.Single(h.PostFolders());
    }

    // ---------- D4 naming ----------

    [Theory]
    [InlineData(".NET", "_NET")]
    [InlineData("..hidden", "__hidden")]
    [InlineData("a.b", "a.b")]
    public void D4_leading_dots_become_underscores(string input, string expected) =>
        Assert.Equal(expected, Naming.Sanitize(input));

    // ---------- D6 network ----------

    [Fact]
    public void D6_network_errors_do_not_count_and_retry_after_15_minutes()
    {
        var pub = new DateTimeOffset(2026, 10, 3, 4, 0, 0, TimeSpan.Zero);
        var meta = new PostMeta { Shortcode = Code, PublishedAt = pub, PublishedAtSource = PublishedSource.Page };
        Snapshot Net(double hours) => new(pub.AddHours(hours), Code, null, hours, FetchStatus.NetworkError, null, null, null, null, null, null, "x");
        var snaps = new List<Snapshot> { new(pub.AddHours(1), Code, "u", 1, FetchStatus.Ok, 5, 1, 1, 1, 1, null, null) };
        snaps.AddRange(Enumerable.Range(0, 20).Select(i => Net(2 + i * 0.25)));
        var s = new Tracker(new TrackerOptions()).Evaluate(meta, snaps);
        Assert.Equal(PostStatus.Tracking, s.Status);
        Assert.Equal(0, s.ConsecutiveFailures);
        Assert.Equal(snaps[^1].CapturedAt.AddMinutes(15), s.NextDue);
    }

    [Fact]
    public void D6_network_error_on_a_later_post_does_not_stop_the_run()
    {
        const string code2 = "DFakeP0st02";
        using var h = WithPost(PostUrl + "\nhttps://www.threads.com/t/" + code2 + "\n");
        h.Pages[code2] = () => throw new HttpRequestException("reset");
        h.Run("run");
        Assert.Equal(2, h.Requests.Count);
        Assert.DoesNotContain("No network connection", h.Text);
    }

    // ---------- D7 redact ----------

    [Theory]
    [InlineData("proxy http://bob:s3cret@10.0.0.1:8080/x failed", "proxy http://***@10.0.0.1:8080/x failed")]
    [InlineData("GET https://www.threads.com/share/ABC/?xmt=TOKEN&a=1 failed", "GET https://www.threads.com/share/ABC/ failed")]
    [InlineData("see www.example.com/p?q=1.", "see www.example.com/p")]
    [InlineData("https://www.threads.com/@user/post/CODE stays", "https://www.threads.com/@user/post/CODE stays")]
    public void D7_redact_removes_userinfo_and_query(string input, string expected) =>
        Assert.Equal(expected, Text.Redact(input));

    [Fact]
    public void D7_exception_text_in_snapshot_is_redacted()
    {
        using var h = WithPost();
        h.Pages[Code] = () => throw new InvalidOperationException("bad https://u:p@host/x?token=SECRET");
        h.Run("run");
        var row = Assert.Single(h.ReadCsv($"Ungrouped/{Directory.GetDirectories(h.PathOf("Ungrouped")).Select(Path.GetFileName).Single()}/snapshots.csv"));
        Assert.DoesNotContain("SECRET", row["error"]);
        Assert.DoesNotContain("u:p", row["error"]);
    }

    // ---------- D8 input ----------

    [Fact]
    public void D8_legacy_input_url_rows_still_map_share_links_and_are_not_rewritten()
    {
        using var h = new Harness();
        const string share = "https://www.threads.com/share/ShareLegacy01/?xmt=OLD";
        h.Urls(share + "\n");
        h.Page(Code, Taken);
        var dir = h.PathOf("Ungrouped", Folder);
        Directory.CreateDirectory(dir);
        var legacy = Csv.Line(FolderStore.LegacySnapshotColumns) + Csv.Line(
        [
            "2026-10-05T08:00:00+08:00", "2026-10-05", "8", Code, PostUrl, "example_user", share,
            "2026-10-05T06:00:00+08:00", "page", "false", "2.00", "ok", "1", "1", "1", "1", "1", "", "",
        ]);
        File.WriteAllText(Path.Combine(dir, "snapshots.csv"), legacy, new UTF8Encoding(true));
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        Assert.DoesNotContain(h.Requests, r => r.Contains("/share/"));
        Assert.Single(h.Requests);
        var text = File.ReadAllText(Path.Combine(dir, "snapshots.csv"));
        Assert.StartsWith("\uFEFF" + legacy, text); // old content untouched
        Assert.Single(text.Split("xmt=OLD")[1..]); // the raw input appears only in the old row
        Assert.DoesNotContain("ShareLegacy01", File.ReadAllText(h.PathOf("index.csv")));
    }

    // ---------- D10 lock ----------

    [Fact]
    public void D10_lock_that_cannot_be_written_stops_with_its_path()
    {
        using var h = WithPost();
        Directory.CreateDirectory(h.PathOf(".lock")); // cannot be opened as a file nor deleted as one
        Assert.Equal(1, h.Run("run"));
        Assert.Contains(h.PathOf(".lock"), h.Text);
        Assert.Empty(h.Requests);
    }

    [Theory]
    [InlineData(10, true)]
    [InlineData(45, false)]
    public void D10_another_computer_within_30_minutes_is_refused(int minutesAgo, bool refused)
    {
        using var h = WithPost();
        File.WriteAllText(h.PathOf(".lock"),
            $"host=OTHERPC\npid=1\ntime={Harness.Start.AddMinutes(-minutesAgo):O}\n");
        var code = h.Run("run");
        if (refused)
        {
            Assert.Equal(1, code);
            Assert.Contains("Only one computer", h.Text);
            Assert.Empty(h.Requests);
            Assert.Contains("OTHERPC", File.ReadAllText(h.PathOf(".lock")));
        }
        else
        {
            Assert.Equal(0, code);
            Assert.Single(h.Requests);
            var info = File.ReadAllText(h.PathOf(".lock"));
            Assert.Contains("host=TESTPC", info);
            Assert.Contains("pid=4242", info);
            Assert.Contains("time=2026-10-05T00:00:00", info);
        }
    }

    [Fact]
    public void D10_conflict_copy_named_after_the_previous_lock_host_is_recognized()
    {
        using var h = WithPost();
        h.Run("run");
        File.WriteAllText(h.PathOf(".lock"), $"host=OTHERPC\npid=1\ntime={Harness.Start.AddDays(-1):O}\n");
        File.Copy(h.PathOf("Ungrouped", Folder, "snapshots.csv"), h.PathOf("Ungrouped", Folder, "snapshots-OTHERPC.csv"));
        File.Copy(h.PathOf("Ungrouped", Folder, "snapshots.csv"), h.PathOf("urls (1).txt"));
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        Assert.Contains("snapshots-OTHERPC.csv", Assert.Single(h.Index())["last_error"]);
        Assert.Contains("urls (1).txt", h.Text);
    }

    // ---------- D11 window ----------

    [Fact]
    public void D11_track_hours_of_the_first_row_wins()
    {
        var pub = new DateTimeOffset(2026, 10, 3, 4, 0, 0, TimeSpan.Zero);
        var meta = new PostMeta { Shortcode = Code, PublishedAt = pub, PublishedAtSource = PublishedSource.Page };
        var s = new Tracker(new TrackerOptions(TrackHours: 168)).Evaluate(meta,
            [new(pub.AddHours(80), Code, "u", 80, FetchStatus.Ok, 5, 1, 1, 1, 1, null, null, 72)]);
        Assert.Equal(PostStatus.Done, s.Status);
        Assert.Equal(pub.AddHours(72), s.TrackUntil);
    }

    [Fact]
    public void D11_no_success_at_all_after_window_is_failed()
    {
        var pub = new DateTimeOffset(2026, 10, 3, 4, 0, 0, TimeSpan.Zero);
        var meta = new PostMeta { Shortcode = Code, PublishedAt = pub, PublishedAtSource = PublishedSource.Page };
        var fails = Enumerable.Range(0, 5).Select(i =>
            new Snapshot(pub.AddHours(80 + i * 0.25), Code, null, 80, FetchStatus.Blocked, null, null, null, null, null, null, "x")).ToList();
        Assert.Equal(PostStatus.Failed, new Tracker(new TrackerOptions()).Evaluate(meta, fails).Status);
    }

    // ---------- D13 schtasks XML ----------

    [Fact]
    public void D13_task_xml_runs_on_battery_every_10_minutes_indefinitely()
    {
        var runner = new FakeProcessRunner();
        string? xml = null;
        runner.Respond = (_, args) =>
        {
            xml = File.ReadAllText(args[Array.IndexOf(args, "/XML") + 1], Encoding.Unicode);
            return new ProcessResult(0, "", "");
        };
        var installer = new ScheduleInstaller(runner, new RealFileOps(), OsKind.Windows, @"C:\Tools\ThreadsMonitor.exe", @"C:\Data\社群 資料",
            true, @"C:\Users\x", TextWriter.Null, () => null, new FakeTime(Harness.Start));
        Assert.Equal(0, installer.Install());

        var doc = XDocument.Parse(xml!);
        XNamespace ns = ScheduleInstaller.TaskNs;
        var settings = doc.Root!.Element(ns + "Settings")!;
        Assert.Equal("false", settings.Element(ns + "DisallowStartIfOnBatteries")!.Value);
        Assert.Equal("false", settings.Element(ns + "StopIfGoingOnBatteries")!.Value);
        Assert.Equal("true", settings.Element(ns + "StartWhenAvailable")!.Value);
        var repetition = doc.Root.Element(ns + "Triggers")!.Element(ns + "TimeTrigger")!.Element(ns + "Repetition")!;
        Assert.Equal("PT10M", repetition.Element(ns + "Interval")!.Value);
        Assert.Null(repetition.Element(ns + "Duration")); // no duration = indefinitely
        Assert.Equal("2026-10-05T08:00:00", doc.Root.Element(ns + "Triggers")!.Element(ns + "TimeTrigger")!.Element(ns + "StartBoundary")!.Value);
        var exec = doc.Root.Element(ns + "Actions")!.Element(ns + "Exec")!;
        Assert.Equal(@"C:\Tools\ThreadsMonitor.exe", exec.Element(ns + "Command")!.Value);
        Assert.StartsWith("run --dir \"", exec.Element(ns + "Arguments")!.Value);
        Assert.EndsWith("社群 資料\"", exec.Element(ns + "Arguments")!.Value);
    }
}
