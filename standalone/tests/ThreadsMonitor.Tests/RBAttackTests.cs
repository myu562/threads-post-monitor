using System.Net;
using ThreadsMonitor.Core;
using ThreadsMonitor.Store;
using Xunit.Abstractions;

namespace ThreadsMonitor.Tests;

/// <summary>
/// R-B: adversarial re-verification. Originally these only printed what happened; FX2 turned
/// every case into an assertion of the correct behavior.
/// </summary>
public class RBAttackTests(ITestOutputHelper o)
{
    private const string Code = Fixtures.Code;
    private const string Code2 = "DFakeP0st02";
    private const string Url = "https://www.threads.com/@example_user/post/" + Code;
    private const string Url2 = "https://www.threads.com/@other_user/post/" + Code2;
    private static readonly DateTimeOffset Taken = Harness.Start.AddHours(-2);

    private static void PageWithCaption(Harness h, string code, string? caption, string user = "example_user", DateTimeOffset? taken = null)
    {
        var json = caption == null ? "null" : "{\"text\":" + System.Text.Json.JsonSerializer.Serialize(caption) + "}";
        var html = Harness.Html(code, taken ?? Taken, 100, 10, user, false).Replace("\"like_count\"", "\"caption\":" + json + ",\"like_count\"");
        h.Pages[code] = () => new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(html) };
    }

    // ---------- poll_bands ----------

    [Fact]
    public void P1_fullwidth_digits_in_poll_bands()
    {
        var warnings = new List<string>();
        var ex = Record.Exception(() => Settings.Parse("poll_bands = ０-２４:６０\n", warnings.Add));
        o.WriteLine("exception: " + (ex?.GetType().Name + ": " + ex?.Message));
        o.WriteLine("warnings: " + string.Join(" | ", warnings));
        Assert.Null(ex);
        var w = Assert.Single(warnings);
        Assert.Contains("poll_bands", w);
        Assert.Contains("half-width", w);
        Assert.Null(Settings.Parse("poll_bands = ０-２４:６０\n").Tracker.PollBands);
        var ex2 = Record.Exception(() => PollPlanner.ParseBands("\u0660-\u0662\u0664:\u0666\u0660"));
        o.WriteLine("arabic-indic: " + (ex2?.GetType().Name ?? "no exception"));
        Assert.Null(ex2);
        Assert.Null(PollPlanner.ParseBands("\u0660-\u0662\u0664:\u0666\u0660"));
    }

    [Fact]
    public void P1b_fullwidth_poll_bands_whole_run()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        h.Page(Code, Taken);
        File.WriteAllText(h.PathOf("settings.ini"), "poll_bands = ０-72:60\n");
        var code = -1;
        var ex = Record.Exception(() => code = h.Run("run"));
        o.WriteLine("run exception: " + (ex?.GetType().Name + ": " + ex?.Message));
        Assert.Null(ex);
        Assert.Equal(0, code);
        Assert.Single(h.Requests); // the default schedule is used
        var ex2 = Record.Exception(() => code = h.Run("status"));
        o.WriteLine("status exception: " + (ex2?.GetType().Name + ": " + ex2?.Message));
        Assert.Null(ex2);
        Assert.Equal(0, code);
        Assert.Contains("poll_bands is invalid", File.ReadAllText(h.PathOf("log.txt")));
    }

    [Theory]
    [InlineData("0-6:30,24-72:60", 7, 24)]
    [InlineData("1-72:60", 0.5, 1)]
    [InlineData("0-24:60,48-72:120", 30, 48)]
    public void P2_gap_in_bands_skips_later_bands(string spec, double elapsedHours, double expectedHours)
    {
        var bands = PollPlanner.ParseBands(spec)!;
        var p = new PollPlanner(72, bands);
        var pub = Harness.Start;
        var next = p.NextPollAt(pub, pub.AddHours(elapsedHours));
        o.WriteLine($"{spec} at {elapsedHours}h -> next at {(next - pub).TotalHours}h");
        Assert.Equal(expectedHours, (next - pub).TotalHours);
    }

    [Fact]
    public void P3_invalid_poll_bands_not_shown_on_console()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        h.Page(Code, Taken);
        File.WriteAllText(h.PathOf("settings.ini"), "poll_bands = 0-24:60,24-72:3\n");
        h.Run("run");
        o.WriteLine("console mentions poll_bands: " + h.Text.Contains("poll_bands"));
        o.WriteLine("log mentions poll_bands: " + File.ReadAllText(h.PathOf("log.txt")).Contains("poll_bands"));
        // A scheduled run (no console) writes it to log.txt only ...
        Assert.DoesNotContain("poll_bands", h.Text);
        Assert.Contains("poll_bands", File.ReadAllText(h.PathOf("log.txt")));
        // ... a person who runs it in a window also sees it there.
        h.Interactive = true;
        h.Run("run");
        Assert.Contains("poll_bands", h.Text);
        h.Interactive = false;
        h.Run("update");
        Assert.Contains("poll_bands", h.Text);
        h.Run("status");
        Assert.Contains("poll_bands", h.Text);
    }

    [Fact]
    public void P4_poll_bands_change_does_not_reopen_done_and_applies_to_tracking()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        h.Page(Code, Taken);
        h.Run("run");
        h.Time.Advance(TimeSpan.FromHours(71));
        h.Run("run"); // 73h after publish -> final
        var st1 = Assert.Single(h.Index())["status"];
        File.WriteAllText(h.PathOf("settings.ini"), "poll_bands = 0-200:60\ntrack_hours = 200\n");
        h.Time.Advance(TimeSpan.FromHours(2));
        var before = h.Requests.Count;
        h.Run("run");
        h.Run("update");
        o.WriteLine($"status before {st1}, after {Assert.Single(h.Index())["status"]}, new requests {h.Requests.Count - before}");
        Assert.Equal("done", Assert.Single(h.Index())["status"]);
        Assert.Equal(before, h.Requests.Count);
    }

    [Fact]
    public void P5_inline_comment_and_spaces()
    {
        var expected = new Dictionary<string, string?>
        {
            ["0-24:60, 24-72:180"] = "0-24:60,24-72:180",
            [" 0 - 24 : 60 "] = "0-24:60",
            ["0-24:60,24-72:180 # less often"] = null,
            ["0-24:60，24-72:180"] = null,
            ["0-24：60"] = null,
            ["0-24:60,"] = null,
            ["\"0-24:60\""] = "0-24:60",
            ["0-72:5"] = "0-72:5",
            ["0-72:4"] = null,
            ["0-0:60"] = null,
            ["24-72:60,0-24:60"] = null,
            ["0-999999:60"] = "0-999999:60",
        };
        foreach (var (v, want) in expected)
        {
            var w = new List<string>();
            var s = Settings.Parse("poll_bands = " + v + "\n", w.Add);
            var got = s.Tracker.PollBands == null ? null : string.Join(",", s.Tracker.PollBands.Select(b => $"{b.FromHour}-{b.ToHour}:{b.IntervalMinutes}"));
            o.WriteLine($"[{v}] -> {got ?? "default"} warn={w.Count}");
            Assert.Equal(want, got);
            Assert.Equal(want == null ? 1 : 0, w.Count); // an invalid value is always reported
        }
        var big = new PollPlanner(720, PollPlanner.ParseBands("0-999999:60")!);
        var pub = Harness.Start;
        o.WriteLine("big band next: " + big.NextPollAt(pub, pub.AddHours(5)));
        Assert.Equal(pub.AddHours(6), big.NextPollAt(pub, pub.AddHours(5)));
        Assert.Equal(pub.AddHours(720), big.NextPollAt(pub, pub.AddHours(719.5)));
    }

    // ---------- urls.txt labels ----------

    [Fact]
    public void L1_tag_before_url_drops_the_whole_line_silently()
    {
        using var h = new Harness();
        h.Urls("#新品 " + Url + "\n");
        h.Page(Code, Taken);
        h.Interactive = true;
        h.Run("run");
        o.WriteLine("requests: " + h.Requests.Count);
        o.WriteLine("console: " + h.Text.Replace("\n", " / "));
        o.WriteLine("log: " + (File.Exists(h.PathOf("log.txt")) ? File.ReadAllText(h.PathOf("log.txt")).Replace("\n", " / ") : "(none)"));
        Assert.Empty(h.Requests); // a comment is still a comment ...
        Assert.Contains("line 1: this line is a comment, so it is not tracked", h.Text); // ... but it is no longer silent
        Assert.Contains("Tags go after the URL", h.Text);
        Assert.Contains("line 1: this line is a comment, so it is not tracked", File.ReadAllText(h.PathOf("log.txt")));
        h.Lang = "zh";
        h.Run("run");
        Assert.Contains("這一行是註解，所以沒有追蹤；標籤要寫在網址後面", h.Text);
    }

    [Fact]
    public void L2_tag_without_space_is_lost_silently()
    {
        using var h = new Harness();
        h.Urls(Url + "#新品\n");
        h.Page(Code, Taken);
        h.Run("run");
        o.WriteLine("tags: [" + Assert.Single(h.Index())["tags"] + "] requests " + h.Requests.Count);
        o.WriteLine("log: " + File.ReadAllText(h.PathOf("log.txt")).Replace("\n", " / "));
        // "#" right after the URL is the URL's fragment (documented): the post is still tracked.
        Assert.Equal("", Assert.Single(h.Index())["tags"]);
        Assert.Single(h.Requests);
    }

    [Fact]
    public void L3_fullwidth_hash_and_bar()
    {
        using var h = new Harness();
        h.Urls(Url + " ＃新品 ｜ 備註\n");
        h.Page(Code, Taken);
        h.Run("run");
        var r = Assert.Single(h.Index());
        o.WriteLine($"tags [{r["tags"]}] note [{r["note"]}]");
        o.WriteLine("console has warning: " + h.Text.Contains("ignored"));
        o.WriteLine("log: " + File.ReadAllText(h.PathOf("log.txt")).Replace("\n", " / "));
        Assert.Equal("新品", r["tags"]); // ＃ and ｜ of a Chinese input method count as # and |
        Assert.Equal("備註", r["note"]);
        Assert.DoesNotContain("ignored", File.ReadAllText(h.PathOf("log.txt")));
    }

    [Fact]
    public void L4_fullwidth_space_separates_tags()
    {
        using var h = new Harness();
        h.Urls(Url + "\u3000#新品\u3000#活動\u3000|\u3000備註\u3000\n");
        h.Page(Code, Taken);
        h.Run("run");
        var r = Assert.Single(h.Index());
        o.WriteLine($"tags [{r["tags"]}] note [{r["note"]}]");
        Assert.Equal("新品;活動", r["tags"]); // tags column is sorted by code point (新 U+65B0 < 活 U+6D3B)
        Assert.Equal("備註", r["note"]);
    }

    [Fact]
    public void L5_invisible_marks_create_distinct_tags()
    {
        using var h = new Harness();
        // LRM (U+200E) and soft hyphen (U+00AD) are not removed like zero-width spaces
        h.Urls(Url + " #新品\u200E #新\u00AD品 #新品\n");
        h.Page(Code, Taken);
        h.Run("run");
        var r = Assert.Single(h.Index());
        o.WriteLine($"tags [{r["tags"].Replace("\u200E", "<LRM>").Replace("\u00AD", "<SHY>")}]");
        Assert.Equal("新品", r["tags"]);
        h.Run("status", "--tag", "新品");
        o.WriteLine(h.Text);
        Assert.Contains("1 of 1", h.Text);
        h.Run("status", "--tag", "新\u00AD品\u200B");
        Assert.Contains("1 of 1", h.Text);
        // URL with LRM directly after it (common when copying from some apps)
        h.Urls(Url2 + "\u200E #a\n");
        PageWithCaption(h, Code2, "x", "other_user");
        h.Run("run");
        o.WriteLine("url+LRM: " + h.Text.Replace("\n", " / "));
        Assert.Contains(h.Index(), row => row["shortcode"] == Code2 && row["tags"] == "a");
    }

    [Fact]
    public void L6_tag_limit_and_multi_line_union()
    {
        using var h = new Harness();
        var t1 = string.Join(' ', Enumerable.Range(1, 25).Select(i => "#a" + i));
        var t2 = string.Join(' ', Enumerable.Range(1, 25).Select(i => "#b" + i));
        h.Urls(Url + " " + t1 + "\n" + Url + " " + t2 + "\nthreads.com/t/" + Code + " #c1 | n3\n");
        h.Page(Code, Taken);
        h.Run("run");
        var r = Assert.Single(h.Index());
        o.WriteLine($"tag count {r["tags"].Split(';').Length} note [{r["note"]}]");
        o.WriteLine("log warnings: " + File.ReadAllLines(h.PathOf("log.txt")).Count(l => l.Contains("more than")));
        Assert.Equal(41, r["tags"].Split(';').Length); // 20 per line, union of the lines
        Assert.Equal("n3", r["note"]);
        Assert.Equal(10, File.ReadAllLines(h.PathOf("log.txt")).Count(l => l.Contains("more than")));
    }

    [Fact]
    public void L7_overlong_tag_and_note()
    {
        using var h = new Harness();
        var emoji = string.Concat(Enumerable.Repeat("\U0001F469\u200D\U0001F4BB", 30)); // woman technologist (ZWJ sequence)
        h.Urls(Url + " #" + new string('x', 31) + " #" + emoji + " | " + new string('n', 5000) + "\n");
        h.Page(Code, Taken);
        h.Run("run");
        var r = Assert.Single(h.Index());
        o.WriteLine($"tags [{r["tags"]}] len={r["tags"].Length} note len {r["note"].Length}");
        Assert.Equal(emoji, r["tags"]); // the joiner inside an emoji is kept
        Assert.Equal(1000, r["note"].Length);
        Assert.Contains("invalid tag", File.ReadAllText(h.PathOf("log.txt")));
    }

    [Theory]
    [InlineData("## =1+1")]
    [InlineData("## @brand")]
    [InlineData("## -活動")]
    [InlineData("## +加碼")]
    public void L8_group_name_formula_injection_in_index(string heading)
    {
        using var h = new Harness();
        h.Urls(heading + "\n" + Url + "\n");
        h.Page(Code, Taken);
        h.Run("run");
        var r = Assert.Single(h.Index());
        o.WriteLine($"{heading}: group cell [{r["group"]}] folder cell [{r["folder"]}]");
        Assert.StartsWith("'", r["group"]);
        Assert.StartsWith("'", r["folder"]);
        Assert.Equal("'" + heading[3..], r["group"]);
    }

    [Theory]
    [InlineData("#=1+1", "tags")]
    [InlineData("#@x", "tags")]
    [InlineData("#a #-x", "tags")]
    [InlineData("| @someone thanks", "note")]
    [InlineData("| \t=1", "note")]
    public void L9_tag_note_formula_cells(string suffix, string col)
    {
        using var h = new Harness();
        h.Urls(Url + " " + suffix + "\n");
        h.Page(Code, Taken);
        h.Run("run");
        var cell = Assert.Single(h.Index())[col];
        o.WriteLine($"{suffix} -> {col} [{cell}]");
        Assert.StartsWith("'", cell);
        Assert.DoesNotMatch("^[=+\\-@\t\r]", cell);
    }

    [Fact]
    public void L10_caption_formula_variants_and_once()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        PageWithCaption(h, Code, "@friend 謝謝");
        h.Run("run");
        var snap = h.ReadCsv("Ungrouped/2026-10-05_06h_@example_user_" + Code + "/snapshots.csv");
        o.WriteLine("snapshot caption [" + snap[0]["caption"] + "] index caption [" + Assert.Single(h.Index())["caption"] + "]");
        Assert.Equal("'@friend 謝謝", snap[0]["caption"]);
        Assert.Equal("'@friend 謝謝", Assert.Single(h.Index())["caption"]);
        h.Run("search", "@friend");
        o.WriteLine(h.Text);
        Assert.Contains("1 of 1", h.Text); // the apostrophe is not part of the text when searching
    }

    [Fact]
    public void L11_caption_only_in_pending_then_excel_resave_without_caption_column()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        PageWithCaption(h, Code, "Hello caption");
        h.Run("run");
        var folder = "Ungrouped/2026-10-05_06h_@example_user_" + Code;
        var path = h.PathOf(folder.Split('/').Append("snapshots.csv").ToArray());
        // user deletes the caption column in Excel and saves with ';'
        var rows = Csv.Parse(File.ReadAllText(path));
        var idx = rows[0].IndexOf("caption");
        File.WriteAllText(path, string.Concat(rows.Select(r => string.Join(';', r.Where((_, i) => i != idx)) + "\r\n")));
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        o.WriteLine(File.ReadAllText(path));
        o.WriteLine("index caption [" + Assert.Single(h.Index())["caption"] + "]");
        // The user removed the column on purpose: the runs go on and every snapshot is kept, in
        // the file's own layout (';' and no caption column).
        var after = Csv.Parse(File.ReadAllText(path));
        Assert.Equal(4, after.Count);
        Assert.DoesNotContain("caption", after[0]);
        Assert.All(after.Skip(1), r => Assert.Equal("ok", r[after[0].IndexOf("status")]));
    }

    // ---------- status / search ----------

    [Fact]
    public void S1_search_edge_cases()
    {
        using var h = new Harness();
        h.Urls("## 夏季活動\n" + Url + " #ＳＡＬＥ #新品 | 第一篇\n" + Url2 + " #sale\n");
        PageWithCaption(h, Code, "Ｈｅｌｌｏ\u3000世界");
        PageWithCaption(h, Code2, "hello -5% off", "other_user");
        h.Run("run");
        var cases = new (string[] Args, int Code, string FirstLine)[]
        {
            (["search", "hello"], 0, "2 of 2"),
            (["search", "ＨＥＬＬＯ"], 0, "2 of 2"),
            (["search", ""], 2, "search needs a word"),
            (["search", "\u3000"], 2, "search needs a word"),
            (["search", "-5%"], 2, "Unknown command: -5%"),
            (["search", "--", "-5%"], 0, "1 of 2"),
            (["search", "off", "-5%"], 0, "0 of 2"),
            (["search", "#"], 0, "2 of 2"),
            (["search", "@"], 0, "2 of 2"),
            (["search", "新品\u200B"], 0, "1 of 2"),
            (["search", "hello", "世界"], 0, "1 of 2"),
            (["search", "--tag"], 2, "--tag needs a value"),
            (["status", "--tag", "SALE"], 0, "2 of 2"),
            (["status", "--tag", "sale"], 0, "2 of 2"),
            (["status", "--tag", "#新品"], 0, "1 of 2"),
            (["status", "--group", "夏季活動"], 0, "2 of 2"),
            (["status", "--group", " 夏季活動 "], 0, "2 of 2"),
            (["status", "--tag", ""], 2, "Not a valid tag"),
            (["status", "--group", ""], 2, "--group needs a value"),
            (["run", "--tag", "x"], 2, "--tag and --group work only"),
            (["search", "x", "--group"], 2, "--group needs a value"),
        };
        foreach (var (args, expectedCode, firstLine) in cases)
        {
            var code = h.Run(args);
            o.WriteLine($"$ {string.Join(' ', args.Select(a => "[" + a + "]"))} -> {code}: {h.Text.Split('\n')[0].Trim()}");
            Assert.Equal(expectedCode, code);
            Assert.Contains(firstLine, h.Text.Split('\n')[0]);
        }
        h.Run("search", "-5%");
        Assert.Contains("search -- -5%", h.Text); // tells how to search for it
    }

    [Fact]
    public void S2_tags_dropped_when_url_removed()
    {
        using var h = new Harness();
        h.Urls(Url + " #keep | note\n");
        h.Page(Code, Taken);
        h.Run("run");
        h.Urls("");
        h.Run("status", "--tag", "keep");
        o.WriteLine(h.Text);
        // Tags live in urls.txt: a removed line takes its tags with it (the data stays).
        Assert.Contains("0 of 1", h.Text);
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        o.WriteLine("index tags after removal [" + Assert.Single(h.Index())["tags"] + "]");
        Assert.Equal("", Assert.Single(h.Index())["tags"]);
        Assert.Single(h.PostFolders());
    }
}

public class RBSettingsTests(Xunit.Abstractions.ITestOutputHelper o)
{
    [Fact]
    public void Lines_without_equals_are_reported()
    {
        foreach (var (line, warnings) in new[] { ("track_hours 48", 1), ("track_hours：48", 1), ("track_hours = 48 # two days", 1), ("sleep_seconds = 3,5", 1), ("[main]", 0), ("poll_bands", 1) })
        {
            var w = new List<string>();
            var s = ThreadsMonitor.Settings.Parse(line + "\n", w.Add);
            o.WriteLine($"[{line}] track={s.Tracker.TrackHours} sleep={s.SleepSeconds} warnings={w.Count}");
            Assert.Equal(72, s.Tracker.TrackHours);
            Assert.Equal(3, s.SleepSeconds);
            Assert.Equal(warnings, w.Count); // every line that is not understood is reported (log.txt)
        }
    }
}
