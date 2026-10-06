using System.Net;
using ThreadsMonitor.Store;

namespace ThreadsMonitor.Tests;

public class AppTests
{
    private const string Code = Fixtures.Code;
    private const string PostUrl = "https://www.threads.com/@example_user/post/" + Code;

    // Published 2 hours before the harness clock starts; local time is UTC+8.
    private static readonly DateTimeOffset Taken = Harness.Start.AddHours(-2);
    private const string Folder = "2026-10-05_06h_@example_user_" + Code;

    private static Harness WithPost(string urls = "## Client A\n" + PostUrl + "\n")
    {
        var h = new Harness();
        h.Urls(urls);
        h.Page(Code, Taken, views: 1234, likes: 56);
        return h;
    }

    [Fact]
    public void First_run_creates_urls_template_and_makes_no_request()
    {
        using var h = new Harness();
        Assert.Equal(0, h.Run("run"));
        Assert.True(File.Exists(h.PathOf("urls.txt")));
        Assert.Contains("## ", File.ReadAllText(h.PathOf("urls.txt")));
        Assert.Empty(h.Requests);
    }

    [Fact]
    public void Run_creates_post_folder_in_group_with_data_info_and_index()
    {
        using var h = WithPost();
        Assert.Equal(0, h.Run("run"));

        Assert.Equal(["Client A/" + Folder], h.PostFolders());
        var rows = h.ReadCsv($"Client A/{Folder}/snapshots.csv");
        var row = Assert.Single(rows);
        Assert.Equal(Code, row["shortcode"]);
        Assert.Equal("2026-10-05T08:00:00+08:00", row["captured_at_local"]);
        Assert.Equal("2026-10-05", row["captured_date"]);
        Assert.Equal("8", row["captured_hour"]);
        Assert.Equal("2026-10-05T06:00:00+08:00", row["published_at_local"]);
        Assert.Equal("page", row["published_at_source"]);
        Assert.False(row.ContainsKey("input_url"));
        Assert.Equal(ThreadsMonitor.Core.InputKey.Hash(Code), row["input_key"]);
        Assert.Equal("72", row["track_hours"]);
        Assert.Equal("2.00", row["hours_since_publish"]);
        Assert.Equal("1234", row["views"]);
        Assert.Equal("", row["shares"]); // null stays empty, never 0

        Assert.True(File.Exists(h.PathOf("Client A", Folder, "info.txt")));
        var bytes = File.ReadAllBytes(h.PathOf("index.csv"));
        Assert.Equal([0xEF, 0xBB, 0xBF], bytes[..3]);
        var index = Assert.Single(h.Index());
        Assert.Equal("tracking", index["status"]);
        Assert.Equal("Client A", index["group"]);
        Assert.Equal($"Client A/{Folder}", index["folder"]);
        Assert.Equal("false", index["archived"]);
        Assert.Equal("2026-10-05T09:00:00+08:00", index["next_due_local"]); // 3h grid point
    }

    [Fact]
    public void Run_fetches_only_when_due()
    {
        using var h = WithPost();
        h.Run("run");
        h.Requests.Clear();

        h.Time.Advance(TimeSpan.FromMinutes(30));
        h.Run("run");
        Assert.Empty(h.Requests);
        Assert.Contains("Nothing is due", h.Text);

        h.Time.Advance(TimeSpan.FromMinutes(30));
        h.Run("run");
        Assert.Single(h.Requests);
        Assert.Equal(2, h.ReadCsv($"Client A/{Folder}/snapshots.csv").Count);
    }

    [Fact]
    public void Manual_update_fetches_every_tracking_post_and_prints_summary()
    {
        using var h = WithPost();
        h.Run("run");
        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromMinutes(5));
        h.Run("update");
        Assert.Single(h.Requests);
        Assert.Contains("@example_user " + Code, h.Text);
        Assert.Contains("1,234", h.Text);
    }

    [Fact]
    public void Moved_folder_is_still_recognised_and_not_refetched()
    {
        using var h = WithPost();
        h.Run("run");
        h.Requests.Clear();

        Directory.CreateDirectory(h.PathOf("Other client", "2026-10"));
        Directory.Move(h.PathOf("Client A", Folder), h.PathOf("Other client", "2026-10", "renamed by user"));

        h.Time.Advance(TimeSpan.FromMinutes(10));
        h.Run("run");
        Assert.Empty(h.Requests); // identity comes from the data, so it is not a "new" post
        Assert.Equal(["Other client/2026-10/renamed by user"], h.PostFolders());
        var index = Assert.Single(h.Index());
        Assert.Equal("Other client", index["group"]);

        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        Assert.Single(h.Requests);
        Assert.Equal(2, h.ReadCsv("Other client/2026-10/renamed by user/snapshots.csv").Count);
    }

    [Theory]
    [InlineData("封存")]
    [InlineData("Archive")]
    public void Archived_post_is_read_only(string archive)
    {
        using var h = WithPost();
        h.Run("run");
        h.Requests.Clear();
        Directory.CreateDirectory(h.PathOf(archive, "2026-10 Client A"));
        Directory.Move(h.PathOf("Client A", Folder), h.PathOf(archive, "2026-10 Client A", Folder));

        h.Time.Advance(TimeSpan.FromHours(5));
        h.Run("update");
        Assert.Empty(h.Requests);
        Assert.Single(h.ReadCsv($"{archive}/2026-10 Client A/{Folder}/snapshots.csv"));
        var index = Assert.Single(h.Index());
        Assert.Equal("true", index["archived"]);
        Assert.Equal("", index["next_due_local"]);
    }

    [Fact]
    public void Deleted_index_is_rebuilt()
    {
        using var h = WithPost();
        h.Run("run");
        File.Delete(h.PathOf("index.csv"));
        h.Time.Advance(TimeSpan.FromMinutes(1));
        h.Run("run");
        Assert.Equal("1234", Assert.Single(h.Index())["views"]);
    }

    [Fact]
    public void Status_does_not_go_online_or_write()
    {
        using var h = WithPost();
        h.Run("run");
        File.Delete(h.PathOf("index.csv"));
        h.Requests.Clear();
        h.Run("status");
        Assert.Empty(h.Requests);
        Assert.False(File.Exists(h.PathOf("index.csv")));
        Assert.Contains("1,234", h.Text);
    }

    [Fact]
    public void Share_link_is_resolved_once_and_remembered_in_the_data()
    {
        using var h = new Harness();
        h.Urls("https://www.threads.com/share/ShareFake01/?xmt=FAKE\n");
        h.Shares["ShareFake01"] = PostUrl + "?xmt=FAKEXMTVALUE01&slof=1";
        h.Page(Code, Taken);

        h.Run("run");
        Assert.Equal(2, h.Requests.Count);
        Assert.Equal(["Ungrouped/" + Folder], h.PostFolders());

        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromMinutes(1));
        h.Run("update");
        Assert.Equal(["https://www.threads.com/t/" + Code], h.Requests);
        Assert.Single(h.PostFolders());
    }

    [Fact]
    public void Removed_url_stops_tracking_and_keeps_data()
    {
        using var h = WithPost();
        h.Run("run");
        h.Urls("# nothing left\n");
        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromHours(2));
        h.Run("update");
        Assert.Empty(h.Requests);
        var index = Assert.Single(h.Index());
        Assert.Contains("urls.txt", index["last_error"]);
        Assert.Single(h.ReadCsv($"Client A/{Folder}/snapshots.csv"));
    }

    [Fact]
    public void Deleted_post_folder_is_recreated_while_url_stays()
    {
        using var h = WithPost();
        h.Run("run");
        new DirectoryInfo(h.PathOf("Client A", Folder)).Delete(recursive: true);
        h.Requests.Clear();
        h.Run("run");
        Assert.Single(h.Requests);
        Assert.Single(h.PostFolders());
    }

    [Fact]
    public void Second_process_skips_when_locked()
    {
        using var h = WithPost();
        var folder = new WorkFolder(h.Root, h.Files, h.Time);
        using (var held = folder.Acquire(h.MachineName, 1).Handle)
        {
            Assert.NotNull(held);
            Assert.Null(folder.TryLock());
            Assert.Equal(0, h.Run("run"));
            Assert.Contains("already running", h.Text);
            Assert.Empty(h.Requests);
        }
        Assert.Equal(0, h.Run("run"));
        Assert.Single(h.Requests);
    }

    [Fact]
    public void Locked_snapshots_go_to_pending_and_are_merged_next_time()
    {
        using var h = WithPost();
        h.Run("run");
        h.Files.Locked.Add("snapshots.csv");
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");

        var pending = Directory.GetFiles(h.PathOf("Client A", Folder), "snapshots.pending-*.csv");
        Assert.Single(pending);
        Assert.Contains("snapshots.pending-", h.Text);
        Assert.Equal("3.00", Assert.Single(h.Index())["hours_since_publish"]); // pending rows are read too

        h.Files.Locked.Clear();
        h.Time.Advance(TimeSpan.FromMinutes(1));
        h.Run("run");
        Assert.Empty(Directory.GetFiles(h.PathOf("Client A", Folder), "snapshots.pending-*.csv"));
        Assert.Equal(["2.00", "3.00"], h.ReadCsv($"Client A/{Folder}/snapshots.csv").Select(r => r["hours_since_publish"]));
    }

    [Fact]
    public void Locked_index_is_skipped_without_pending_file()
    {
        using var h = WithPost();
        h.Files.Locked.Add("index.csv");
        Assert.Equal(0, h.Run("run"));
        Assert.Contains("index.csv", h.Text);
        Assert.False(File.Exists(h.PathOf("index.csv")));
        Assert.False(File.Exists(h.PathOf("index.csv.tmp")));
        Assert.Empty(Directory.GetFiles(h.Root, "*.pending-*"));
        Assert.Single(h.PostFolders());
    }

    [Fact]
    public void Gone_post_stops_and_keeps_last_numbers_as_final()
    {
        using var h = WithPost();
        h.Run("run");
        h.Pages.Remove(Code); // now 404
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        var index = Assert.Single(h.Index());
        Assert.Equal("gone", index["status"]);
        Assert.Equal("1234", index["final_views"]);

        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("update");
        Assert.Empty(h.Requests);
    }

    [Fact]
    public void Final_after_computer_was_off_is_late_with_real_time()
    {
        using var h = WithPost();
        h.Run("run");
        h.Time.Advance(TimeSpan.FromHours(80)); // computer off until 82h after publishing
        h.Run("run");
        var index = Assert.Single(h.Index());
        Assert.Equal("done", index["status"]);
        Assert.Equal("true", index["late_final"]);
        Assert.Equal("2026-10-08T16:00:00+08:00", index["final_captured_at_local"]);
    }

    [Fact]
    public void Post_added_after_window_is_flagged()
    {
        using var h = new Harness();
        h.Urls(PostUrl + "\n");
        h.Page(Code, Harness.Start.AddDays(-10));
        h.Run("run");
        var index = Assert.Single(h.Index());
        Assert.Equal("done", index["status"]);
        Assert.Equal("true", index["added_after_window"]);
        Assert.Equal(index["views"], index["final_views"]);
    }

    [Fact]
    public void Counts_hidden_writes_empty_cells_not_zero()
    {
        using var h = new Harness();
        h.Urls(PostUrl + "\n");
        h.Page(Code, Taken, hidden: true);
        h.Run("run");
        var row = Assert.Single(h.ReadCsv($"Ungrouped/{Folder}/snapshots.csv"));
        Assert.Equal("", row["views"]);
        Assert.Equal("", row["likes"]);
        Assert.Equal("3", row["replies"]);
        Assert.Equal("true", row["counts_hidden"]);
    }

    [Fact]
    public void Shortcodes_differing_only_in_case_get_their_own_folders()
    {
        using var h = new Harness();
        h.Files.CaseInsensitive = true;
        h.Urls("https://www.threads.com/t/DFakeP0st0a\nhttps://www.threads.com/t/DFakeP0st0A\n");
        h.Page("DFakeP0st0a", Taken);
        h.Page("DFakeP0st0A", Taken);
        h.Run("run");

        var folders = h.PostFolders();
        Assert.Equal(2, folders.Count);
        Assert.Equal(2, folders.Distinct(StringComparer.OrdinalIgnoreCase).Count());
        Assert.Contains(folders, f => f.EndsWith("_2", StringComparison.Ordinal));
        var codes = folders.Select(f => Assert.Single(h.ReadCsv(f + "/snapshots.csv"))["shortcode"]).OrderBy(c => c, StringComparer.Ordinal);
        Assert.Equal(["DFakeP0st0A", "DFakeP0st0a"], codes);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void Folder_with_unknown_date_is_renamed_once_when_the_date_is_known(bool renameFails)
    {
        var code = new string('_', 60); // decodes to no usable creation time
        using var h = new Harness();
        h.Urls("https://www.threads.com/t/" + code + "\n");
        h.Pages[code] = () => throw new HttpRequestException("offline");
        h.Run("run");
        Assert.Equal(["Ungrouped/0000-00-00_00h_" + code], h.PostFolders());

        h.Files.FailDirectoryMoves = renameFails;
        h.Page(code, Taken);
        h.Time.Advance(TimeSpan.FromMinutes(15));
        h.Run("run");

        var expected = renameFails ? "Ungrouped/0000-00-00_00h_" + code : "Ungrouped/2026-10-05_06h_@example_user_" + code;
        Assert.Equal([expected], h.PostFolders());
        Assert.Equal(2, h.ReadCsv(expected + "/snapshots.csv").Count);
        if (renameFails)
        {
            Assert.Contains("could not rename", File.ReadAllText(h.PathOf("log.txt")));
        }
    }

    [Fact]
    public void Unknown_date_folder_moved_by_the_user_is_not_renamed()
    {
        var code = new string('_', 60);
        using var h = new Harness();
        h.Urls("https://www.threads.com/t/" + code + "\n");
        h.Pages[code] = () => throw new HttpRequestException("offline");
        h.Run("run");
        Directory.CreateDirectory(h.PathOf("Mine", "deeper"));
        Directory.Move(h.PathOf("Ungrouped", "0000-00-00_00h_" + code), h.PathOf("Mine", "deeper", "kept"));

        h.Page(code, Taken);
        h.Time.Advance(TimeSpan.FromMinutes(15));
        h.Run("run");
        Assert.Equal(["Mine/deeper/kept"], h.PostFolders());
    }

    [Fact]
    public void Same_url_in_two_groups_uses_the_first_group()
    {
        using var h = WithPost("## First\n" + PostUrl + "\n## Second\n" + PostUrl + "\n");
        h.Run("run");
        Assert.Equal(["First/" + Folder], h.PostFolders());
        Assert.Contains("another group", File.ReadAllText(h.PathOf("log.txt")));
    }

    [Fact]
    public void Ungrouped_folder_keeps_the_language_it_was_created_in()
    {
        using var h = new Harness { Lang = "zh" };
        h.Urls(PostUrl + "\n");
        h.Page(Code, Taken);
        h.Run("run");
        Assert.Equal(["未分組/" + Folder], h.PostFolders());

        h.Lang = "en";
        h.Urls(PostUrl + "\nhttps://www.threads.com/@example_user/post/DFakeP0st02\n");
        h.Page("DFakeP0st02", Taken);
        h.Run("run");
        Assert.All(h.PostFolders(), f => Assert.StartsWith("未分組/", f));
    }

    [Fact]
    public void Copied_post_folder_is_not_fetched_until_resolved()
    {
        using var h = WithPost();
        h.Run("run");
        var copy = h.PathOf("Client A", "copy");
        Directory.CreateDirectory(copy);
        File.Copy(h.PathOf("Client A", Folder, "snapshots.csv"), Path.Combine(copy, "snapshots.csv"));
        h.Requests.Clear();
        h.Time.Advance(TimeSpan.FromHours(2));
        h.Run("update");
        Assert.Empty(h.Requests);
        Assert.Contains("more than one folder", Assert.Single(h.Index())["last_error"]);
    }

    private const int LongestFile = 47; // "snapshots.pending-yyyyMMddHHmmss-NN.csv.merging"

    /// <summary>A work folder under the temp root, with the Windows path limit set relative to it.</summary>
    private static string WindowsRoot(Harness h, int room)
    {
        var root = Path.Combine(h.Root, "d");
        Directory.CreateDirectory(root);
        h.Os = OsKind.Windows;
        h.MaxPathLength = root.Length + room;
        return root;
    }

    [Fact]
    public void Long_path_uses_short_folder_name()
    {
        using var h = new Harness();
        var group = new string('g', 40);
        // Full name: root + group + 41-char folder + longest file is one over the limit; short name (22 chars) fits.
        var root = WindowsRoot(h, 1 + group.Length + 1 + Folder.Length + 1 + LongestFile - 1);
        File.WriteAllText(Path.Combine(root, "urls.txt"), "## " + group + "\n" + PostUrl + "\n");
        h.Page(Code, Taken);
        h.Run("run", "--dir", root);

        var data = Assert.Single(Directory.GetFiles(root, "snapshots.csv", SearchOption.AllDirectories));
        Assert.Equal("2026-10-05_" + Code, Path.GetFileName(Path.GetDirectoryName(data)));
    }

    [Fact]
    public void Path_too_long_even_for_short_name_is_reported_not_crashing()
    {
        using var h = new Harness();
        var root = WindowsRoot(h, 40);
        File.WriteAllText(Path.Combine(root, "urls.txt"), PostUrl + "\n");
        h.Page(Code, Taken);
        Assert.Equal(0, h.Run("run", "--dir", root));
        Assert.Contains("too long", h.Text);
        Assert.Empty(h.Requests); // checked before fetching: nothing is fetched in vain
        Assert.Empty(Directory.GetFiles(root, "snapshots.csv", SearchOption.AllDirectories));
    }

    [Fact]
    public void Help_follows_language()
    {
        using var h = new Harness { Lang = "zh" };
        h.Run("--help");
        Assert.Contains("用法", h.Text);
        h.Lang = "en";
        h.Run("--help");
        Assert.Contains("Usage", h.Text);
    }

    [Fact]
    public void Language_follows_ui_culture_without_option()
    {
        Assert.Same(Strings.Chinese, Text.Select(null, new System.Globalization.CultureInfo("zh-TW")));
        Assert.Same(Strings.English, Text.Select(null, new System.Globalization.CultureInfo("fr-FR")));
        Assert.Same(Strings.English, Text.Select("en", new System.Globalization.CultureInfo("zh-TW")));
    }

    [Fact]
    public void Unknown_command_returns_2()
    {
        using var h = new Harness();
        Assert.Equal(2, h.Run("explode"));
        Assert.Contains("Unknown command", h.Text);
    }

    [Fact]
    public void Requests_are_paced()
    {
        using var h = WithPost("## A\n" + PostUrl + "\nhttps://www.threads.com/@example_user/post/DFakeP0st02\n");
        h.Page("DFakeP0st02", Taken);
        h.Run("run");
        Assert.Equal(2, h.Requests.Count);
        Assert.Equal([TimeSpan.FromSeconds(3)], h.Sleeps);
    }
}
