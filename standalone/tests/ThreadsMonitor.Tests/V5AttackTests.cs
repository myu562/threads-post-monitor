using System.Net;
using System.Text;
using ThreadsMonitor.Core;
using ThreadsMonitor.Store;

namespace ThreadsMonitor.Tests;

/// <summary>Files where Delete of pending files can be made to fail (Excel has the pending file open).</summary>
public sealed class AttackFileOps : RealFileOps
{
    public bool FailPendingDelete { get; set; }
    public HashSet<string> Locked { get; } = new(StringComparer.Ordinal);

    private void Check(string path)
    {
        if (Locked.Contains(Path.GetFileName(path)))
        {
            throw new IOException("locked");
        }
    }

    public override void AppendAllText(string path, string text) { Check(path); base.AppendAllText(path, text); }
    public override void WriteAllText(string path, string text, bool withBom) { Check(path); base.WriteAllText(path, text, withBom); }

    public override void Delete(string path)
    {
        if (FailPendingDelete && Path.GetFileName(path).StartsWith("snapshots.pending-", StringComparison.Ordinal))
        {
            throw new IOException("The process cannot access the file because it is being used by another process.");
        }
        base.Delete(path);
    }
}

/// <summary>
/// The attacks of the V5 review, turned into regression tests: each one now asserts the correct
/// behavior instead of the failure it originally demonstrated.
/// </summary>
public class V5AttackTests
{
    private const string Code = Fixtures.Code;
    private const string PostUrl = "https://www.threads.com/@example_user/post/" + Code;
    private static readonly DateTimeOffset Taken = Harness.Start.AddHours(-2);
    private const string Folder = "2026-10-05_06h_@example_user_" + Code;

    private static Harness WithPost(string urls)
    {
        var h = new Harness();
        h.Urls(urls);
        h.Page(Code, Taken, views: 1234, likes: 56);
        return h;
    }

    private static int RunWith(Harness h, IFileOps files, params string[] args)
    {
        var e = h.Env();
        var env = new AppEnvironment
        {
            Out = e.Out, Time = e.Time, Files = files, BaseDirectory = e.BaseDirectory, ExePath = e.ExePath, Os = e.Os,
            HomeDirectory = e.HomeDirectory, HttpFactory = e.HttpFactory, Processes = e.Processes, UiCulture = e.UiCulture,
            Sleep = e.Sleep, MachineName = e.MachineName,
        };
        return new App(env).RunAsync([.. args, "--lang", "en"]).GetAwaiter().GetResult();
    }

    private static string Log(Harness h) => File.ReadAllText(h.PathOf("log.txt"));

    private static void AssertNoDuplicateRows(List<Dictionary<string, string>> rows) =>
        Assert.Equal(rows.Count, rows.Select(r => r["captured_at_local"] + "|" + r["status"]).Distinct().Count());

    // (b) pending file cannot be deleted after merge: no crash, fetching goes on, no duplicated rows
    [Fact]
    public void B1_pending_delete_failure_does_not_crash_or_duplicate()
    {
        using var h = WithPost(PostUrl + "\n");
        var files = new AttackFileOps();
        Assert.Equal(0, RunWith(h, files, "run"));
        files.Locked.Add("snapshots.csv");
        h.Time.Advance(TimeSpan.FromHours(1));
        Assert.Equal(0, RunWith(h, files, "run"));
        files.Locked.Clear();
        Assert.Single(Directory.GetFiles(h.PathOf("Ungrouped", Folder), "snapshots.pending-*.csv"));

        files.FailPendingDelete = true;
        h.Requests.Clear();
        for (var i = 0; i < 3; i++)
        {
            h.Time.Advance(TimeSpan.FromHours(1));
            Assert.Null(Record.Exception(() => Assert.Equal(0, RunWith(h, files, "run"))));
        }
        Assert.Equal(3, h.Requests.Count); // fetching was never blocked by the held file

        var rows = h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv");
        Assert.Equal(5, rows.Count); // first + pending + 3 new, each once
        AssertNoDuplicateRows(rows);
        Assert.Contains("could not merge", Log(h));

        files.FailPendingDelete = false;
        RunWith(h, files, "run");
        var after = h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv");
        Assert.Equal(5, after.Count);
        Assert.Empty(Directory.GetFiles(h.PathOf("Ungrouped", Folder), "snapshots.pending-*"));
    }

    // (d)/(b) user deletes a column in Excel: later rows are written by column name, never misaligned
    [Fact]
    public void B2_excel_deleted_column_keeps_rows_aligned()
    {
        using var h = WithPost(PostUrl + "\n");
        h.Run("run");
        var path = h.PathOf("Ungrouped", Folder, "snapshots.csv");
        var rows = Csv.Parse(File.ReadAllText(path));
        var idx = rows[0].IndexOf("input_key");
        foreach (var r in rows) r.RemoveAt(idx);
        File.WriteAllText(path, string.Concat(rows.Select(r => Csv.Line(r))), new UTF8Encoding(true));

        for (var i = 0; i < 6; i++)
        {
            h.Time.Advance(TimeSpan.FromHours(1));
            h.Run("run");
        }
        var data = h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv");
        Assert.Equal(7, data.Count);
        Assert.All(data, r => Assert.Equal("ok", r["status"]));
        Assert.All(data, r => Assert.Equal("2026-10-05T06:00:00+08:00", r["published_at_local"]));
        Assert.All(data, r => Assert.False(r.ContainsKey("input_key")));
        var index = Assert.Single(h.Index());
        Assert.Equal("tracking", index["status"]);
        Assert.Contains("columns differ", Log(h));
    }

    // (d) European Excel saves with ';': the post is still found, no second folder, no extra fetch
    [Fact]
    public void B3_semicolon_csv_is_still_read_and_appended_with_semicolons()
    {
        using var h = WithPost(PostUrl + "\n");
        h.Run("run");
        var path = h.PathOf("Ungrouped", Folder, "snapshots.csv");
        var rows = Csv.Parse(File.ReadAllText(path));
        File.WriteAllText(path, string.Concat(rows.Select(r => string.Join(";", r) + "\r\n")), Encoding.Latin1);
        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromMinutes(10)); // not due yet
        h.Run("run");
        Assert.Empty(h.Requests);
        Assert.Single(h.PostFolders());

        h.Time.Advance(TimeSpan.FromMinutes(50)); // due
        h.Run("run");
        Assert.Single(h.Requests);
        var text = File.ReadAllText(path);
        Assert.Equal(';', Csv.DetectDelimiter(text));
        var data = Csv.ParseWithHeader(text);
        Assert.Equal(2, data.Count);
        Assert.All(data, r => Assert.Equal(Code, r["shortcode"]));
    }

    // (d) snapshots.csv saved without final newline: a line break is added before the next row
    [Fact]
    public void B4_missing_trailing_newline_is_repaired_before_appending()
    {
        using var h = WithPost(PostUrl + "\n");
        h.Run("run");
        var path = h.PathOf("Ungrouped", Folder, "snapshots.csv");
        File.WriteAllText(path, File.ReadAllText(path).TrimEnd('\r', '\n'), new UTF8Encoding(true));
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        var data = h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv");
        Assert.Equal(2, data.Count);
        Assert.Equal("", data[0]["error"]);
        Assert.Equal("ok", data[1]["status"]);
        Assert.StartsWith("2026-10-05T09:00:00", data[1]["captured_at_local"]);
    }

    // (b) a corrupt pending file is never merged: it becomes .bad.csv and is logged
    [Fact]
    public void B5_corrupt_pending_is_set_aside_as_bad()
    {
        using var h = WithPost(PostUrl + "\n");
        h.Run("run");
        File.WriteAllText(h.PathOf("Ungrouped", Folder, "snapshots.pending-20261005090000.csv"),
            "garbage header\r\nnot,a,row\r\n\"unterminated,quote\r\n");
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        var text = File.ReadAllText(h.PathOf("Ungrouped", Folder, "snapshots.csv"));
        Assert.DoesNotContain("not,a,row", text);
        Assert.Equal(2, h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv").Count(r => r["status"] == "ok"));
        Assert.True(File.Exists(h.PathOf("Ungrouped", Folder, "snapshots.pending-20261005090000.bad.csv")));
        Assert.Empty(Directory.GetFiles(h.PathOf("Ungrouped", Folder), "snapshots.pending-*.merging"));
        Assert.Contains("not merged", Log(h));

        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run"); // the .bad.csv file is left alone from now on
        Assert.Equal(3, h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv").Count);
    }

    // (d) "## 封存" / "## Archive" as a group name: the group folder gets a leading _ and is tracked
    [Theory]
    [InlineData("封存")]
    [InlineData("Archive")]
    public void D1_group_named_archive_is_renamed_and_tracked(string group)
    {
        using var h = WithPost($"## {group}\n{PostUrl}\n");
        h.Run("run");
        Assert.Single(h.Requests);
        for (var i = 0; i < 5; i++)
        {
            h.Time.Advance(TimeSpan.FromHours(1));
            h.Run("run");
        }
        var index = Assert.Single(h.Index());
        Assert.Equal(6, h.Requests.Count);
        Assert.Equal("false", index["archived"]);
        Assert.Equal("tracking", index["status"]);
        Assert.Equal("_" + group, index["group"]);
        Assert.Equal([$"_{group}/{Folder}"], h.PostFolders());
        Assert.Contains("archive folder", Log(h));
    }

    // (d) group name with a leading dot: the folder starts with _ instead (never hidden)
    [Fact]
    public void D2_group_with_leading_dot_gets_a_visible_folder()
    {
        using var h = WithPost($"## .NET 社群\n{PostUrl}\n");
        for (var i = 0; i < 3; i++)
        {
            h.Run("run");
            h.Time.Advance(TimeSpan.FromMinutes(10));
        }
        Assert.Single(h.Requests);
        Assert.Equal([$"_NET 社群/{Folder}"], h.PostFolders());
        Assert.Single(h.Index());
    }

    // (d) "##客戶A" without a space is a group heading too
    [Fact]
    public void D3_group_heading_without_space_is_a_group()
    {
        using var h = WithPost($"##客戶A\n###comment\n{PostUrl}\n");
        h.Run("run");
        Assert.Equal([$"客戶A/{Folder}"], h.PostFolders());
    }

    // (d) urls.txt saved as ANSI/Big5: decoded with a legacy code page, with a warning
    [Fact]
    public void D4_big5_urls_group_is_decoded()
    {
        using var h = new Harness();
        var bytes = new List<byte>();
        bytes.AddRange(Encoding.ASCII.GetBytes("## "));
        bytes.AddRange([0xAB, 0xC8, 0xA4, 0xE1]); // 客戶 in Big5
        bytes.AddRange(Encoding.ASCII.GetBytes("A\r\n" + PostUrl + "\r\n"));
        File.WriteAllBytes(h.PathOf("urls.txt"), bytes.ToArray());
        h.Page(Code, Taken);
        h.Run("run");
        var folder = Assert.Single(h.PostFolders());
        Assert.StartsWith("客戶A/", folder);
        Assert.DoesNotContain('\uFFFD', folder);
        Assert.Contains("not saved as UTF-8", Log(h));
    }

    // (d) zero-width space copied from a chat app in front of the URL is removed
    [Fact]
    public void D5_zero_width_space_is_removed()
    {
        using var h = WithPost("\u200B" + PostUrl + "\u200C\n## \u2060Client\uFEFF\n");
        h.Run("run");
        Assert.Single(h.Requests);
        Assert.Equal([$"Ungrouped/{Folder}"], h.PostFolders());
    }

    // (d) user moves a post folder inside another post folder: still found, not refetched, warned
    [Fact]
    public void D6_post_folder_nested_in_other_post_is_still_found()
    {
        const string code2 = "DFakeP0st02";
        using var h = WithPost(PostUrl + "\nhttps://www.threads.com/@example_user/post/" + code2 + "\n");
        h.Page(code2, Taken);
        h.Run("run");
        var f2 = h.PostFolders().Single(f => f.EndsWith(code2));
        Directory.Move(h.PathOf(f2.Split('/')), h.PathOf("Ungrouped", Folder, Path.GetFileName(f2)));
        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromMinutes(10));
        h.Run("run");
        Assert.Empty(h.Requests);
        Assert.Equal(2, h.PostFolders().Count);
        Assert.Equal(2, h.Index().Count);
        Assert.Contains("inside another post folder", Log(h));
    }

    // (f) network down for ~1 hour: nothing is counted as failed, the rest of the run is skipped
    [Fact]
    public void F1_offline_hour_does_not_fail_posts()
    {
        const string code2 = "DFakeP0st02";
        using var h = WithPost(PostUrl + "\nhttps://www.threads.com/@example_user/post/" + code2 + "\n");
        h.Page(code2, Taken);
        h.Run("run");
        h.Pages[Code] = () => throw new HttpRequestException("No such host is known.");
        h.Pages[code2] = () => throw new HttpRequestException("No such host is known.");
        h.Time.Advance(TimeSpan.FromHours(1));
        for (var i = 0; i < 5; i++)
        {
            h.Requests.Clear();
            Assert.Equal(0, h.Run("run"));
            Assert.Single(h.Requests); // first post fails -> the second one is not tried
            Assert.Contains("No network connection", h.Text);
            h.Time.Advance(TimeSpan.FromMinutes(15));
        }
        h.Page(Code, Taken, views: 9999);
        h.Page(code2, Taken, views: 8888);
        h.Requests.Clear();
        h.Run("run");
        Assert.Equal(2, h.Requests.Count);
        var index = h.Index();
        Assert.All(index, r => Assert.Equal("tracking", r["status"]));
        Assert.Contains(index, r => r["views"] == "9999");
    }

    // (e) deep work folder: no Windows limit elsewhere, so it is saved and not refetched
    [Fact]
    public void E1_deep_folder_is_saved_once_outside_windows()
    {
        using var h = new Harness();
        var root = h.Root;
        while (root.Length < 205) root = Path.Combine(root, "深層資料夾");
        Directory.CreateDirectory(root);
        File.WriteAllText(Path.Combine(root, "urls.txt"), PostUrl + "\n");
        h.Page(Code, Taken);
        for (var i = 0; i < 3; i++)
        {
            h.Run("run", "--dir", root);
            h.Time.Advance(TimeSpan.FromMinutes(10));
        }
        Assert.Single(h.Requests);
        Assert.Single(Directory.GetFiles(root, "snapshots.csv", SearchOption.AllDirectories));
    }

    // (e) on Windows, a path that does not fit even with the short name: reported once per run, never fetched
    [Fact]
    public void E1_path_too_long_on_windows_is_not_fetched_and_reported_once_per_run()
    {
        using var h = new Harness { Os = OsKind.Windows };
        var root = Path.Combine(h.Root, "深層資料夾");
        Directory.CreateDirectory(root);
        h.MaxPathLength = root.Length + 30;
        File.WriteAllText(Path.Combine(root, "urls.txt"), PostUrl + "\n");
        h.Page(Code, Taken);
        for (var i = 0; i < 3; i++)
        {
            Assert.Equal(0, h.Run("run", "--dir", root));
            Assert.Single(h.Text.Split('\n'), l => l.Contains("too long"));
            h.Time.Advance(TimeSpan.FromMinutes(10));
        }
        Assert.Empty(h.Requests);
        Assert.Empty(Directory.GetFiles(root, "snapshots.csv", SearchOption.AllDirectories));
        Assert.Equal(3, File.ReadAllLines(Path.Combine(root, "log.txt")).Count(l => l.Contains("too long")));
    }

    // (e) path with spaces / CJK / quote / percent in the cron line and the Windows task
    [Fact]
    public void E2_cron_line_quotes_odd_paths()
    {
        var dir = "/home/u/My Docs/社群 監測/it's 100%";
        var inst = new ScheduleInstaller(new FakeProcessRunner(), new RealFileOps(), OsKind.Linux, "/opt/x/Threads Monitor", dir, true, "/home/u", TextWriter.Null, () => "n");
        Assert.Contains("'/opt/x/Threads Monitor' run --dir '/home/u/My Docs/社群 監測/it'\\''s 100\\%'", inst.CronLine());
        var win = new ScheduleInstaller(new FakeProcessRunner(), new RealFileOps(), OsKind.Windows, @"C:\Users\王\OneDrive\Threads Monitor\ThreadsMonitor.exe", @"C:\Users\王\OneDrive\社群 資料\", true, @"C:\Users\王", TextWriter.Null, () => "n");
        Assert.StartsWith("\"C:\\Users\\王\\OneDrive\\Threads Monitor\\ThreadsMonitor.exe\" run --dir \"", win.WindowsTaskCommand());
        Assert.EndsWith("社群 資料\"", win.WindowsTaskCommand());
    }

    // (g) tracking parameters in the input URL are never stored
    [Fact]
    public void G1_xmt_query_is_not_kept_in_files()
    {
        var url = PostUrl + "?xmt=FAKEXMTAQF&slof=1";
        using var h = WithPost(url + "\n");
        h.Run("run");
        foreach (var f in new[] { h.PathOf("Ungrouped", Folder, "snapshots.csv"), h.PathOf("index.csv"), h.PathOf("Ungrouped", Folder, "info.txt"), h.PathOf("log.txt") })
        {
            Assert.DoesNotContain("xmt=", File.ReadAllText(f));
        }
        Assert.Equal(InputKey.Hash(Code), h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv")[0]["input_key"]);
    }

    // (g) the share code never reaches index.csv or snapshots.csv, yet it is not resolved again
    [Fact]
    public void G2_share_code_is_not_stored_but_still_recognized()
    {
        using var h = new Harness();
        h.Urls("https://www.threads.com/share/SHAREcodeSECRET1/\n");
        h.Shares["SHAREcodeSECRET1"] = PostUrl;
        h.Page(Code, Taken);
        h.Run("run");
        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromMinutes(10));
        h.Run("run");
        Assert.Empty(h.Requests); // mapping remembered through input_key
        h.Urls("# removed\n");
        h.Run("run");
        Assert.DoesNotContain("SHAREcodeSECRET1", File.ReadAllText(h.PathOf("index.csv")));
        Assert.DoesNotContain("SHAREcodeSECRET1", File.ReadAllText(h.PathOf("Ungrouped", Folder, "snapshots.csv")));
        Assert.DoesNotContain("SHAREcodeSECRET1", Log(h));
        Assert.Equal(InputKey.Hash("SHAREcodeSECRET1"), h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv")[0]["input_key"]);
    }

    // (g) log and console for a failed share link and an invalid line
    [Fact]
    public void G3_log_and_console_do_not_contain_secrets()
    {
        using var h = new Harness();
        h.Urls("https://www.threads.com/share/SHAREcodeSECRET2/\nhttps://user:pa55word@example.com/?token=SECRETTOKEN\n");
        h.Run("run");
        var log = Log(h);
        Assert.DoesNotContain("SHAREcodeSECRET2", log);
        Assert.DoesNotContain("SECRETTOKEN", log);
        Assert.DoesNotContain("SECRETTOKEN", h.Text);
        Assert.DoesNotContain("pa55word", h.Text);
        Assert.Contains("https://***@example.com/", h.Text);
    }

    // (d) Excel turns true/false into TRUE/FALSE: still understood
    [Fact]
    public void D7_excel_uppercase_true_keeps_counts_hidden()
    {
        using var h = new Harness();
        h.Urls(PostUrl + "\n");
        h.Page(Code, Harness.Start.AddHours(-80), views: null, hidden: true);
        h.Run("run");
        Assert.Equal("true", Assert.Single(h.Index())["counts_hidden"]);
        var path = Directory.GetFiles(h.Root, "snapshots.csv", SearchOption.AllDirectories).Single();
        File.WriteAllText(path, File.ReadAllText(path).Replace(",true,", ",TRUE,").Replace(",false,", ",FALSE,"), new UTF8Encoding(true));
        File.Delete(h.PathOf("index.csv"));
        h.Run("run");
        var idx = Assert.Single(h.Index());
        Assert.Equal("true", idx["counts_hidden"]);
        Assert.Equal("done", idx["status"]);
    }

    // (c) clock jumps far ahead then back: the future snapshot is ignored and reported
    [Fact]
    public void C1_clock_forward_then_back_ignores_future_snapshot()
    {
        using var h = WithPost(PostUrl + "\n");
        h.Run("run");
        h.Time.Advance(TimeSpan.FromDays(365));
        h.Run("update");
        h.Time.Advance(TimeSpan.FromDays(-365) + TimeSpan.FromHours(1));
        h.Requests.Clear();
        h.Run("run");
        var idx = Assert.Single(h.Index());
        Assert.Equal("tracking", idx["status"]);
        Assert.Equal("", idx["final_views"]);
        Assert.Contains("check the computer clock", idx["last_error"]);
        Assert.Single(h.Requests); // the regular hourly fetch still happens
    }

    // (c) clock before publish: no negative age, the row says why
    [Fact]
    public void C2_clock_before_publish_writes_zero_hours_and_a_note()
    {
        using var h = WithPost(PostUrl + "\n");
        h.Time.UtcNow = Taken.AddDays(-3);
        h.Run("run");
        var row = Assert.Single(h.ReadCsv(h.PostFolders()[0] + "/snapshots.csv"));
        Assert.Equal("0.00", row["hours_since_publish"]);
        Assert.Equal("ok", row["status"]);
        Assert.Contains(Session.ClockBeforePublish, row["error"]);
        Assert.Equal("tracking", Assert.Single(h.Index())["status"]);
    }

    // (c) off for 5 days: exactly one catch-up request, late_final
    [Fact]
    public void C3_off_five_days_single_catchup()
    {
        using var h = WithPost(PostUrl + "\n");
        h.Run("run");
        h.Time.Advance(TimeSpan.FromDays(5));
        h.Requests.Clear();
        h.Run("run");
        h.Time.Advance(TimeSpan.FromMinutes(10));
        h.Run("run");
        var idx = Assert.Single(h.Index());
        Assert.Single(h.Requests);
        Assert.Equal("true", idx["late_final"]);
        Assert.Equal("done", idx["status"]);
    }

    // (c) timezone change between runs: no extra fetch
    [Fact]
    public void C4_timezone_change()
    {
        using var h = WithPost(PostUrl + "\n");
        h.Run("run");
        h.Time.Zone = TimeZoneInfo.CreateCustomTimeZone("m5", TimeSpan.FromHours(-5), "m5", "m5");
        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromMinutes(30));
        h.Run("run");
        Assert.Empty(h.Requests);
        Assert.Single(h.PostFolders());
    }

    // (c) DST fall-back night (America/New_York 2026-11-01): one fetch per hour of real time
    [Fact]
    public void C5_dst_fallback()
    {
        var ny = TimeZoneInfo.FindSystemTimeZoneById("America/New_York");
        using var h = new Harness();
        h.Time.Zone = ny;
        var pub = new DateTimeOffset(2026, 11, 1, 5, 30, 0, TimeSpan.Zero); // 01:30 EDT
        h.Time.UtcNow = pub.AddMinutes(5);
        h.Urls(PostUrl + "\n");
        h.Page(Code, pub);
        h.Run("run");
        for (var i = 0; i < 12; i++)
        {
            h.Time.Advance(TimeSpan.FromMinutes(10));
            h.Run("run");
        }
        var rows = h.ReadCsv(h.PostFolders()[0] + "/snapshots.csv");
        Assert.Equal(3, rows.Count); // +5min, +1h, +2h
    }

    // (f) two URL forms of the same post under different groups: fetched once, first group wins
    [Fact]
    public void F2_same_post_two_forms_two_groups()
    {
        using var h = WithPost($"## A\nhttps://threads.net/t/{Code}/\n## B\n{PostUrl}?igshid=1\n");
        h.Run("run");
        Assert.Single(h.Requests);
        Assert.StartsWith("A/", Assert.Single(h.PostFolders()));
    }

    // (f) failures at the final poll: 5 retries, then done with the last real numbers (like the web version)
    [Fact]
    public void H1_failure_after_window_ends_done_with_late_final()
    {
        using var h = WithPost(PostUrl + "\n");
        h.Run("run");
        h.Time.UtcNow = Taken.AddHours(72);
        h.Pages[Code] = () => new HttpResponseMessage(HttpStatusCode.TooManyRequests) { Content = new StringContent("") };
        h.Run("run");
        var idx = Assert.Single(h.Index());
        Assert.Equal("tracking", idx["status"]);
        Assert.Equal("2026-10-08T06:15:00+08:00", idx["next_due_local"]);
        for (var i = 0; i < 4; i++) { h.Time.Advance(TimeSpan.FromMinutes(15)); h.Run("run"); }
        idx = Assert.Single(h.Index());
        Assert.Equal("done", idx["status"]);
        Assert.Equal("1234", idx["final_views"]);
        Assert.Equal("2026-10-05T08:00:00+08:00", idx["final_captured_at_local"]);
        Assert.Equal("true", idx["late_final"]);
    }

    // (a) read-only .lock (OneDrive / copied from a read-only medium): replaced, the run works
    [Fact]
    public void A1_readonly_lock_file_is_replaced()
    {
        using var h = WithPost(PostUrl + "\n");
        File.WriteAllText(h.PathOf(".lock"), "");
        File.SetUnixFileMode(h.PathOf(".lock"), UnixFileMode.UserRead);
        Assert.Null(Record.Exception(() => Assert.Equal(0, h.Run("run"))));
        Assert.Single(h.Requests);
        Assert.Contains("host=TESTPC", File.ReadAllText(h.PathOf(".lock")));
    }

    // (d) duplicate copy of a post folder by a sync program: tracking goes on in the original, user is told
    [Fact]
    public void E3_conflict_copy_keeps_tracking_and_asks_to_merge()
    {
        using var h = WithPost(PostUrl + "\n");
        h.MachineName = "LAPTOP";
        h.Run("run");
        var src = h.PathOf("Ungrouped", Folder);
        var dst = src + "-LAPTOP";
        Directory.CreateDirectory(dst);
        foreach (var f in Directory.GetFiles(src)) File.Copy(f, Path.Combine(dst, Path.GetFileName(f)));
        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        var idx = Assert.Single(h.Index());
        Assert.Single(h.Requests);
        Assert.Equal("tracking", idx["status"]);
        Assert.Equal($"Ungrouped/{Folder}", idx["folder"]);
        Assert.Contains("merge them by hand", idx["last_error"]);
        Assert.Contains($"Ungrouped/{Folder}-LAPTOP", h.Text);
        Assert.Equal(2, h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv").Count);
        Assert.Single(h.ReadCsv($"Ungrouped/{Folder}-LAPTOP/snapshots.csv"));
    }

    // (f) changing track_hours in settings.ini does not reopen finished posts
    [Fact]
    public void F3_settings_change_does_not_reopen_done_post()
    {
        using var h = new Harness();
        h.Urls(PostUrl + "\n");
        h.Page(Code, Harness.Start.AddHours(-80));
        h.Run("run");
        Assert.Equal("done", Assert.Single(h.Index())["status"]);
        File.WriteAllText(h.PathOf("settings.ini"), "track_hours = 168\n");
        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromMinutes(10));
        h.Run("run");
        var idx = Assert.Single(h.Index());
        Assert.Equal("done", idx["status"]);
        Assert.Empty(h.Requests);
    }
}
