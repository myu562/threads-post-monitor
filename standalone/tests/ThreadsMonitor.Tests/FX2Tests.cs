using System.Reflection;
using ThreadsMonitor.Core;
using ThreadsMonitor.Store;

namespace ThreadsMonitor.Tests;

/// <summary>FX2: fixes for the R-B findings (S1-S10 of the work order).</summary>
public class FX2Tests
{
    private const string Code = Fixtures.Code;
    private const string Url = "https://www.threads.com/@example_user/post/" + Code;
    private static readonly DateTimeOffset Taken = Harness.Start.AddHours(-2);

    private static string Log(Harness h) => File.Exists(h.PathOf("log.txt")) ? File.ReadAllText(h.PathOf("log.txt")) : "";

    // ---------- S1: no settings value can stop the program ----------

    public static readonly string[] SettingKeys =
    [
        "user_agent", "timeout_seconds", "sleep_seconds", "track_hours", "retry_minutes",
        "max_consecutive_failures", "late_final_tolerance_minutes", "poll_bands",
    ];

    public static readonly string[] HostileValues =
    [
        "\uFF14\uFF18",                         // full-width 48
        "\uFF10-\uFF17\uFF12:\uFF16\uFF10",     // full-width 0-72:60
        "\u0664\u0668",                         // Arabic-Indic 48
        "\u0660-\u0667\u0662:\u0666\u0660",     // Arabic-Indic 0-72:60
        "99999999999999999999999999",
        "0-99999999999:60",
        "-5",
        "-1-72:60",
        "",
        "   ",
        "\u3000",
        "1e309",
        "NaN",
        "\u4E2D\u6587",
    ];

    public static IEnumerable<object[]> EveryKeyAndValue() =>
        from k in SettingKeys from v in HostileValues select new object[] { k, v };

    [Theory]
    [MemberData(nameof(EveryKeyAndValue))]
    public void S1_any_value_of_any_setting_never_crashes(string key, string value)
    {
        var warnings = new List<string>();
        var ex = Record.Exception(() => Settings.Parse(key + " = " + value + "\n", warnings.Add));
        Assert.Null(ex);

        using var h = new Harness();
        h.Urls(Url + "\n");
        h.Page(Code, Taken);
        File.WriteAllText(h.PathOf("settings.ini"), key + " = " + value + "\n");
        Assert.Equal(0, h.Run("run"));
        Assert.Equal(0, h.Run("status"));
        Assert.Equal(0, h.Run("update"));
        Assert.Equal(0, h.Run("--help"));
        Assert.Single(h.Index());
    }

    [Fact]
    public void S1_full_width_digits_are_reported_with_a_hint_and_the_default_is_used()
    {
        var warnings = new List<string>();
        var s = Settings.Parse("track_hours = \uFF14\uFF18\npoll_bands = \uFF10-72:60\n", warnings.Add);
        Assert.Equal(72, s.Tracker.TrackHours);
        Assert.Null(s.Tracker.PollBands);
        Assert.Equal(2, warnings.Count);
        Assert.All(warnings, w => Assert.Contains("half-width digits", w));
    }

    [Fact]
    public void S1_a_user_agent_that_is_not_plain_ascii_keeps_the_default()
    {
        var warnings = new List<string>();
        var s = Settings.Parse("user_agent = \u700F\u89BD\u5668\n", warnings.Add);
        Assert.Equal(Settings.DefaultUserAgent, s.UserAgent);
        Assert.Single(warnings);
    }

    [Fact]
    public void S1_no_unicode_digit_pattern_is_left_in_the_source()
    {
        // \d matches every script's digits; the program only ever wants 0-9.
        var src = Path.Combine(FindStandalone(), "src", "ThreadsMonitor");
        foreach (var file in Directory.GetFiles(src, "*.cs", SearchOption.AllDirectories)
                     .Where(f => !f.Contains(Path.DirectorySeparatorChar + "obj" + Path.DirectorySeparatorChar)))
        {
            var lines = File.ReadAllLines(file);
            for (var i = 0; i < lines.Length; i++)
            {
                var code = lines[i].TrimStart();
                if (code.StartsWith("//", StringComparison.Ordinal))
                {
                    continue;
                }
                Assert.False(code.Contains("\\d", StringComparison.Ordinal), $"{Path.GetFileName(file)}:{i + 1} uses \\d");
                Assert.False(code.Contains("int.Parse(", StringComparison.Ordinal), $"{Path.GetFileName(file)}:{i + 1} uses int.Parse");
            }
        }
    }

    // ---------- S2: gaps between bands (same cases as the web version) ----------

    [Theory]
    // 0-24:60,48-72:60 -- a gap from 24 to 48 hours
    [InlineData("0-24:60,48-72:60", 0, 1)]
    [InlineData("0-24:60,48-72:60", 0.5, 1)]
    [InlineData("0-24:60,48-72:60", 23.5, 24)]
    [InlineData("0-24:60,48-72:60", 24, 48)]
    [InlineData("0-24:60,48-72:60", 30, 48)]
    [InlineData("0-24:60,48-72:60", 47.99, 48)]
    [InlineData("0-24:60,48-72:60", 48, 49)]
    [InlineData("0-24:60,48-72:60", 71.5, 72)]
    [InlineData("0-24:60,48-72:60", 72, 72)]
    [InlineData("0-24:60,48-72:60", 80, 72)]
    // 1-72:60 -- nothing before the first hour (the first fetch when added still happens)
    [InlineData("1-72:60", 0, 1)]
    [InlineData("1-72:60", 0.5, 1)]
    [InlineData("1-72:60", 1, 2)]
    [InlineData("1-72:60", 1.5, 2)]
    [InlineData("1-72:60", 71.9, 72)]
    // 0-6:30,24-72:60 -- every 30 minutes, a long gap, then hourly
    [InlineData("0-6:30,24-72:60", 0, 0.5)]
    [InlineData("0-6:30,24-72:60", 5.9, 6)]
    [InlineData("0-6:30,24-72:60", 6, 24)]
    [InlineData("0-6:30,24-72:60", 7, 24)]
    [InlineData("0-6:30,24-72:60", 24, 25)]
    [InlineData("0-6:30,24-72:60", 71, 72)]
    public void S2_next_poll_with_gaps(string spec, double elapsedHours, double expectedHours)
    {
        var planner = new PollPlanner(72, PollPlanner.ParseBands(spec)!);
        var pub = Harness.Start;
        var next = planner.NextPollAt(pub, pub.AddSeconds(Math.Round(elapsedHours * 3600)));
        Assert.Equal(expectedHours, Math.Round((next - pub).TotalHours, 4));
    }

    [Fact]
    public void S2_after_the_last_band_the_next_poll_is_the_final_one()
    {
        var planner = new PollPlanner(72, PollPlanner.ParseBands("0-24:60")!);
        var pub = Harness.Start;
        Assert.Equal(pub.AddHours(24), planner.NextPollAt(pub, pub.AddHours(23.5)));
        Assert.Equal(pub.AddHours(72), planner.NextPollAt(pub, pub.AddHours(24)));
        Assert.Equal(pub.AddHours(72), planner.NextPollAt(pub, pub.AddHours(50)));
    }

    [Fact]
    public void S2_a_band_that_starts_after_track_hours_goes_to_the_final_poll()
    {
        var planner = new PollPlanner(48, PollPlanner.ParseBands("0-6:60,60-72:60")!);
        var pub = Harness.Start;
        Assert.Equal(pub.AddHours(48), planner.NextPollAt(pub, pub.AddHours(10)));
    }

    [Fact]
    public void S2_first_fetch_when_added_then_nothing_until_the_first_band()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        h.Page(Code, Harness.Start); // published just now
        File.WriteAllText(h.PathOf("settings.ini"), "poll_bands = 1-72:60\n");
        h.Run("run");
        Assert.Single(h.Requests); // added: fetched at once

        h.Time.Advance(TimeSpan.FromMinutes(30));
        h.Run("run");
        Assert.Single(h.Requests); // before the first band: not due

        h.Time.Advance(TimeSpan.FromMinutes(30));
        h.Run("run");
        Assert.Equal(2, h.Requests.Count); // 1 hour: the first band starts
    }

    [Fact]
    public void S2_gap_is_not_polled_in_a_whole_run()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        h.Page(Code, Harness.Start);
        File.WriteAllText(h.PathOf("settings.ini"), "poll_bands = 0-6:30,24-72:60\n");
        h.Run("run");
        var count = h.Requests.Count;
        for (var t = 1; t <= 4 * 30; t++) // 30 hours in 15-minute steps
        {
            h.Time.Advance(TimeSpan.FromMinutes(15));
            h.Run("run");
            var hours = (h.Time.GetUtcNow() - Harness.Start).TotalHours;
            if (hours is > 6 and < 24)
            {
                Assert.Equal(count, h.Requests.Count);
            }
            count = h.Requests.Count;
        }
        // 0 (added) + every 30 min up to 6 h (12) + 24..30 h hourly (7)
        Assert.Equal(1 + 12 + 7, h.Requests.Count);
    }

    // ---------- S3: --help describes the schedule in effect ----------

    [Fact]
    public void S3_default_help_matches_the_default_bands()
    {
        using var h = new Harness();
        h.Run("--help");
        Assert.Contains(Text.Schedule(Strings.English, PollPlanner.DefaultBands, 72), h.Text);
        var band = Assert.Single(PollPlanner.DefaultBands);
        Assert.Contains($"{band.FromHour}-{band.ToHour} h after publishing every {band.IntervalMinutes} minutes", h.Text);
        Assert.Contains("a last fetch at exactly 72 hours", h.Text);
        Assert.DoesNotContain("every 3 hours", h.Text); // the old hand-written text

        h.Lang = "zh";
        h.Run("--help");
        Assert.Contains(Text.Schedule(Strings.Chinese, PollPlanner.DefaultBands, 72), h.Text);
        Assert.Contains($"\u767C\u6587\u5F8C {band.FromHour}\u2013{band.ToHour} \u5C0F\u6642\u6BCF {band.IntervalMinutes} \u5206\u9418\u6293\u4E00\u6B21", h.Text);
        Assert.DoesNotContain("\u6BCF 3 \u5C0F\u6642", h.Text);
    }

    [Fact]
    public void S3_help_follows_poll_bands_and_track_hours()
    {
        using var h = new Harness();
        File.WriteAllText(h.PathOf("settings.ini"), "poll_bands = 1-24:60,48-72:180\ntrack_hours = 96\n");
        h.Run("--help");
        Assert.Contains("tracks public Threads posts for 96 hours", h.Text);
        Assert.Contains("no fetch 0-1 h (except the first fetch when a post is added)", h.Text);
        Assert.Contains("1-24 h after publishing every 60 minutes", h.Text);
        Assert.Contains("no fetch 24-48 h", h.Text);
        Assert.Contains("48-72 h after publishing every 3 hours", h.Text);
        Assert.Contains("no fetch 72-96 h", h.Text);
        Assert.Contains("a last fetch at exactly 96 hours", h.Text);

        h.Lang = "zh";
        h.Run("--help");
        Assert.Contains("\u8FFD\u8E64 Threads \u516C\u958B\u8CBC\u6587 96 \u5C0F\u6642", h.Text);
        Assert.Contains("\u767C\u6587\u5F8C 48\u201372 \u5C0F\u6642\u6BCF 3 \u5C0F\u6642\u6293\u4E00\u6B21", h.Text);
        Assert.Contains("24\u201348 \u5C0F\u6642\u4E0D\u6293", h.Text);
        Assert.Contains("96 \u5C0F\u6642\u6574\u518D\u6293\u6700\u5F8C\u4E00\u6B21", h.Text);
    }

    [Fact]
    public void S3_help_with_dir_reads_that_folders_settings()
    {
        using var h = new Harness();
        var other = h.PathOf("other");
        Directory.CreateDirectory(other);
        File.WriteAllText(Path.Combine(other, "settings.ini"), "poll_bands = 0-72:120\n");
        h.Run("--help", "--dir", other);
        Assert.Contains("0-72 h after publishing every 2 hours", h.Text);
    }

    // ---------- S4: formula protection in index.csv and snapshots.csv ----------

    [Fact]
    public void S4_shortcode_and_username_are_protected_and_read_back()
    {
        using var h = new Harness();
        const string code = "-FakeP0st1";
        h.Urls("https://www.threads.com/t/" + code + "\n"); // no account in the URL: the page's account is used
        h.Page(code, Taken, user: "=evil");
        h.Run("run");
        var row = Assert.Single(h.Index());
        Assert.Equal("'" + code, row["shortcode"]);
        Assert.Equal("'=evil", row["username"]);
        var folder = Assert.Single(h.PostFolders());
        var snap = Assert.Single(h.ReadCsv(folder + "/snapshots.csv"));
        Assert.Equal("'" + code, snap["shortcode"]);
        Assert.Equal("'=evil", snap["username"]);

        // read back: still the same post, not a broken or second folder
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        Assert.Equal(folder, Assert.Single(h.PostFolders()));
        Assert.Equal(2, h.ReadCsv(folder + "/snapshots.csv").Count);
        h.Run("status");
        Assert.Contains("@=evil " + code, h.Text);
        Assert.DoesNotContain("'", h.Text.Split('\n')[2]);
    }

    [Fact]
    public void S4_every_text_column_that_comes_from_outside_is_protected()
    {
        // Columns written as they are must be ones this program controls.
        var programColumns = new HashSet<string>
        {
            "url", "published_date", "published_hour", "published_at_local", "published_at_source", "status",
            "hours_since_publish", "views", "likes", "replies", "reposts", "quotes", "shares",
            "final_views", "final_likes", "final_replies", "final_reposts", "final_quotes", "final_shares",
            "final_captured_at_local", "late_final", "added_after_window", "counts_hidden", "next_due_local",
            "archived", "captured_at_local", "captured_date", "captured_hour", "input_key", "track_hours",
        };
        var outside = FolderStore.IndexColumns.Concat(FolderStore.SnapshotColumns).Distinct()
            .Where(c => !programColumns.Contains(c)).OrderBy(c => c).ToList();

        using var h = new Harness();
        h.Urls("## =grp\nhttps://www.threads.com/t/" + Code + " #=t | =n\n");
        var html = Harness.Html(Code, Taken, 100, 10, "@user", false)
            .Replace("\"like_count\"", "\"caption\":{\"text\":\"=cap\"},\"like_count\"");
        h.Pages[Code] = () => new HttpResponseMessage(System.Net.HttpStatusCode.OK) { Content = new StringContent(html) };
        h.Run("run");
        var index = Assert.Single(h.Index());
        var snap = Assert.Single(h.ReadCsv(Assert.Single(h.PostFolders()) + "/snapshots.csv"));
        foreach (var c in outside)
        {
            var cell = index.TryGetValue(c, out var v) ? v : snap.GetValueOrDefault(c, "");
            Assert.DoesNotMatch("^[=+\\-@\\t\\r]", cell);
        }
        Assert.Equal("'=grp", index["group"]);
        Assert.StartsWith("'=grp/", index["folder"]);
        Assert.Equal("'@user", index["username"]);
    }

    [Fact]
    public void S4_error_text_is_protected_and_read_back()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        h.Pages[Code] = () => throw new InvalidOperationException("x");
        h.Run("run");
        var folder = Assert.Single(h.PostFolders());
        var path = h.PathOf(folder.Split('/').Append("snapshots.csv").ToArray());
        // An error that starts like a formula (simulated: Threads/HTTP text is not under our control)
        var rows = Csv.Parse(File.ReadAllText(path));
        var err = rows[0].IndexOf("error");
        rows[1][err] = "'-1 error";
        File.WriteAllText(path, string.Concat(rows.Select(r => Csv.Line(r))));
        Assert.Equal(0, h.Run("status"));
        var index = Assert.Single(h.Index());
        Assert.DoesNotMatch("^[=+\\-@]", index["note"]);
    }

    // ---------- S5: urls.txt feedback ----------

    [Fact]
    public void S5_template_comments_and_commented_out_urls_give_no_warning()
    {
        foreach (var t in new[] { Strings.English, Strings.Chinese })
        {
            using var h = new Harness();
            h.Urls(t.UrlsTemplate + "# " + Url + "\n#" + Url + "\n### " + Url + "\n");
            var warnings = new List<string>();
            var urls = new WorkFolder(h.Root, new RealFileOps(), TimeProvider.System).ReadUrls(warnings.Add, warnings.Add);
            Assert.Empty(urls);
            Assert.Empty(warnings);
        }
    }

    [Theory]
    [InlineData("#\u65B0\u54C1 ")]
    [InlineData("\uFF03\u65B0\u54C1 ")]
    [InlineData("#sale #video ")]
    [InlineData("#sale\u3000")]
    public void S5_tag_before_url_is_reported(string prefix)
    {
        using var h = new Harness();
        h.Urls(prefix + Url + "\n");
        var log = new List<string>();
        var shown = new List<string>();
        Assert.Empty(new WorkFolder(h.Root, new RealFileOps(), TimeProvider.System).ReadUrls(log.Add, shown.Add));
        Assert.Contains(log, w => w.Contains("this line is a comment"));
        Assert.Contains(shown, w => w.Contains("this line is a comment"));
    }

    [Fact]
    public void S5_warnings_reach_the_console_except_in_a_scheduled_run()
    {
        using var h = new Harness();
        h.Urls(Url + " #ok extra | note\n");
        h.Page(Code, Taken);

        h.Run("run"); // scheduled (no console)
        Assert.DoesNotContain("ignored", h.Text);
        Assert.Contains("text after the URL ignored", Log(h));

        h.Run("update");
        Assert.Contains("urls.txt line 1: \"extra\" was ignored", h.Text);

        h.Interactive = true;
        h.Run("run");
        Assert.Contains("urls.txt line 1: \"extra\" was ignored", h.Text);

        h.Interactive = false;
        h.Lang = "zh";
        h.Run();
        Assert.Contains("urls.txt \u7B2C 1 \u884C\uFF1A\u300Cextra\u300D\u5DF2\u5FFD\u7565", h.Text);
    }

    [Fact]
    public void S5_full_width_hash_and_bar_in_groups_and_tags()
    {
        using var h = new Harness();
        h.Urls("\uFF03\uFF03 \u5BA2\u6236A\n" + Url + " \uFF03a\uFF03b \uFF03c \uFF5C n1 \uFF5C n2\n");
        h.Page(Code, Taken);
        h.Run("run");
        var r = Assert.Single(h.Index());
        Assert.Equal("\u5BA2\u6236A", r["group"]);
        Assert.Equal("a#b;c", r["tags"]);
        Assert.Equal("n1 | n2", r["note"]);
    }

    [Theory]
    [InlineData("a\u200Bb", "ab")]          // zero width space
    [InlineData("a\u200Cb", "ab")]          // zero width non-joiner
    [InlineData("a\u200Db", "ab")]          // joiner between letters
    [InlineData("a\u200Eb\u200F", "ab")]    // LRM, RLM
    [InlineData("a\u00ADb", "ab")]          // soft hyphen
    [InlineData("\uFEFFab\u2060", "ab")]    // BOM, word joiner
    [InlineData("a\u202Ab\u202C", "ab")]    // embedding
    [InlineData("\U0001F469\u200D\U0001F4BB", "\U0001F469\u200D\U0001F4BB")]                 // emoji ZWJ sequence
    [InlineData("\U0001F469\U0001F3FD\u200D\U0001F4BB", "\U0001F469\U0001F3FD\u200D\U0001F4BB")] // with skin tone
    [InlineData("\U0001F3F3\uFE0F\u200D\U0001F308", "\U0001F3F3\uFE0F\u200D\U0001F308")]   // rainbow flag
    [InlineData("\u2764\uFE0F\u200D\U0001F525", "\u2764\uFE0F\u200D\U0001F525")]            // heart on fire
    [InlineData("\U0001F469\u200D", "\U0001F469")]                                          // dangling joiner
    [InlineData("\U0001F3F4\U000E0067\U000E0062\U000E0065\U000E006E\U000E0067\U000E007F",
        "\U0001F3F4\U000E0067\U000E0062\U000E0065\U000E006E\U000E0067\U000E007F")]           // flag of England
    [InlineData("a\U000E0067", "a")]                                                        // stray tag character
    public void S5_invisible_characters_are_removed_but_emoji_joiners_kept(string input, string expected)
    {
        Assert.Equal(expected, Labels.StripInvisible(input));
    }

    [Fact]
    public void S5_a_tag_with_invisible_characters_is_invalid()
    {
        Assert.Null(Labels.NormalizeTag("#a\u200Bb"));
        Assert.Null(Labels.NormalizeTag("#a\u200Eb"));
        Assert.Null(Labels.NormalizeTag("#a\u00ADb"));
        Assert.Equal("\U0001F469\u200D\U0001F4BB", Labels.NormalizeTag("#\U0001F469\u200D\U0001F4BB"));
        var warnings = new List<string>();
        var line = Labels.ParseLine(Url + " #a\u200Eb #ok", warnings.Add);
        Assert.Equal(["ok"], line.Tags);
        Assert.Contains(warnings, w => w.Contains("<U+200E>"));
    }

    // ---------- S6: settings.ini lines without = ----------

    [Fact]
    public void S6_line_without_equals_is_written_to_the_log()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        h.Page(Code, Taken);
        File.WriteAllText(h.PathOf("settings.ini"), "[main]\n# comment\n; comment\n\ntrack_hours 48\ntrack_hours\uFF1D48\n=5\n");
        h.Run("run");
        var log = Log(h);
        Assert.Contains("ignored line (expected key = value): track_hours 48", log);
        Assert.Contains("(use half-width = : , -): track_hours\uFF1D48", log);
        Assert.Contains("ignored line (expected key = value): =5", log);
        Assert.DoesNotContain("[main]", log);
        Assert.DoesNotContain("comment", log);
    }

    // ---------- S7: option values ----------

    [Theory]
    [InlineData("status", "--tag")]
    [InlineData("status", "--group")]
    [InlineData("status", "--dir")]
    [InlineData("status", "--lang")]
    [InlineData("status", "--dir", "--lang", "zh")]
    [InlineData("status", "--lang", "--dir", "x")]
    [InlineData("status", "--tag", "--group", "g")]
    [InlineData("status", "--group", "--tag", "t")]
    [InlineData("status", "--tag=--x")]
    [InlineData("status", "--dir=--x")]
    [InlineData("status", "--group", " ")]
    [InlineData("search", "x", "--tag")]
    public void S7_option_without_a_value_is_an_error(params string[] args)
    {
        using var h = new Harness();
        Assert.Equal(2, h.Run(args));
        Assert.Contains("needs a value", h.Text);
        Assert.False(Directory.Exists(h.PathOf("--lang")));
        Assert.False(Directory.Exists(h.PathOf("--x")));
    }

    [Fact]
    public void S7_double_dash_ends_the_options()
    {
        using var h = new Harness();
        h.Urls(Url + " #-sale | --tag in a note\n");
        h.Page(Code, Taken);
        h.Run("run");

        Assert.Equal(0, h.Run("search", "--", "-sale"));
        Assert.Contains("1 of 1", h.Text);
        Assert.Equal(0, h.Run("search", "--", "--tag"));
        Assert.Contains("Search \"--tag\": 1 of 1", h.Text);
        Assert.Equal(0, h.Run("search", "--tag", "-sale", "--", "--tag", "in"));
        Assert.Contains("1 of 1", h.Text);
        Assert.Equal(2, h.Run("status", "--", "extra"));
    }

    [Fact]
    public void S7_valid_option_forms_still_work()
    {
        using var h = new Harness();
        h.Urls("## g\n" + Url + " #t\n");
        h.Page(Code, Taken);
        h.Run("run");
        Assert.Equal(0, h.Run("status", "--tag", "t", "--group", "g"));
        Assert.Contains("1 of 1", h.Text);
        Assert.Equal(0, h.Run("status", "--tag=t", "--group=g"));
        Assert.Contains("1 of 1", h.Text);
        Assert.Equal(0, h.Run("status", "--dir", h.Root));
        Assert.Equal(0, h.Run("status", "--dir=" + h.Root));
    }

    // ---------- S8: tag comparison = search comparison ----------

    [Fact]
    public void S8_tags_compare_with_nfkc_and_without_case()
    {
        Assert.True(Labels.TagComparer.Equals("\uFF33\uFF21\uFF2C\uFF25", "sale"));
        Assert.True(Labels.TagComparer.Equals("\uFF11\uFF10\u6708", "10\u6708"));
        Assert.Equal(Labels.TagComparer.GetHashCode("\uFF33\uFF21\uFF2C\uFF25"), Labels.TagComparer.GetHashCode("sale"));
        Assert.False(Labels.TagComparer.Equals("sale", "sales"));
    }

    [Fact]
    public void S8_lines_merge_and_status_filters_with_the_same_rule_as_search()
    {
        using var h = new Harness();
        h.Urls(Url + " #\uFF33\uFF21\uFF2C\uFF25 #\uFF11\uFF10\u6708\n" + Url + " #sale #10\u6708 #new\n");
        h.Page(Code, Taken);
        h.Run("run");
        var r = Assert.Single(h.Index());
        Assert.Equal("new;\uFF11\uFF10\u6708;\uFF33\uFF21\uFF2C\uFF25", r["tags"]); // first spelling kept, no duplicates

        Assert.Equal(0, h.Run("status", "--tag", "sale"));
        Assert.Contains("1 of 1", h.Text);
        Assert.Equal(0, h.Run("status", "--tag", "10\u6708"));
        Assert.Contains("1 of 1", h.Text);
        Assert.Equal(0, h.Run("search", "sale"));
        Assert.Contains("1 of 1", h.Text);
    }

    [Fact]
    public void S8_search_keyword_without_zero_width_characters()
    {
        using var h = new Harness();
        h.Urls(Url + " #sale\n");
        h.Page(Code, Taken);
        h.Run("run");
        Assert.Equal(0, h.Run("search", "\u200Bsa\u200Ble\uFEFF"));
        Assert.Contains("Search \"sale\": 1 of 1", h.Text);
        Assert.Equal(2, h.Run("search", "\u200B\u200C"));
    }

    // ---------- S9: the urls.txt template ends without a real group ----------

    [Fact]
    public void S9_urls_pasted_at_the_end_of_the_template_are_ungrouped()
    {
        foreach (var lang in new[] { "en", "zh" })
        {
            using var h = new Harness { Lang = lang };
            Assert.Equal(0, h.Run("run")); // creates urls.txt from the template
            var template = File.ReadAllText(h.PathOf("urls.txt"));
            Assert.Contains("# ## ", template);
            Assert.DoesNotMatch("(?m)^##", template);

            File.AppendAllText(h.PathOf("urls.txt"), Url + "\n");
            var urls = new WorkFolder(h.Root, new RealFileOps(), TimeProvider.System).ReadUrls();
            var u = Assert.Single(urls);
            Assert.Null(u.Group);
        }
    }

    [Fact]
    public void S9_urls_pasted_at_the_end_of_the_example_file_are_ungrouped()
    {
        var example = File.ReadAllText(Path.Combine(FindStandalone(), "examples", "urls.txt"));
        using var h = new Harness();
        h.Urls(example + Url + "\n");
        var urls = new WorkFolder(h.Root, new RealFileOps(), TimeProvider.System).ReadUrls();
        Assert.Contains(urls, x => x.Group == "Client A"); // the example still shows groups
        Assert.Null(urls[^1].Group);
        Assert.Equal(Url, urls[^1].Url);
    }

    // ---------- S10: no commit id in the version ----------

    [Fact]
    public void S10_informational_version_has_no_source_revision()
    {
        var v = typeof(App).Assembly.GetCustomAttribute<AssemblyInformationalVersionAttribute>()!.InformationalVersion;
        Assert.Equal("1.0.0", v);
        Assert.DoesNotContain("+", v);
    }

    // ---------- helpers ----------

    private static string FindStandalone()
    {
        for (var d = new DirectoryInfo(AppContext.BaseDirectory); d != null; d = d.Parent)
        {
            if (File.Exists(Path.Combine(d.FullName, "ThreadsMonitor.sln")))
            {
                return d.FullName;
            }
        }
        throw new InvalidOperationException("standalone folder not found");
    }
}
