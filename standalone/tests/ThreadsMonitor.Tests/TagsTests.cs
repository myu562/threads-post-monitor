using System.Net;
using ThreadsMonitor.Core;
using ThreadsMonitor.Store;

namespace ThreadsMonitor.Tests;

/// <summary>S2: tags and notes in urls.txt, the post caption, status filters and search.</summary>
public class TagsTests
{
    private const string Code = Fixtures.Code;
    private const string Code2 = "DFakeP0st02";
    private const string Url = "https://www.threads.com/@example_user/post/" + Code;
    private const string Url2 = "https://www.threads.com/@other_user/post/" + Code2;
    private static readonly DateTimeOffset Taken = Harness.Start.AddHours(-2);
    private const string Folder = "Ungrouped/2026-10-05_06h_@example_user_" + Code;

    private static void PageWithCaption(Harness h, string code, string? caption, string user = "example_user")
    {
        var json = caption == null ? "null" : "{\"text\":" + System.Text.Json.JsonSerializer.Serialize(caption) + "}";
        var html = Harness.Html(code, Taken, 100, 10, user, false).Replace("\"like_count\"", "\"caption\":" + json + ",\"like_count\"");
        h.Pages[code] = () => new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(html) };
    }

    private static Harness TwoPosts(string urls)
    {
        var h = new Harness();
        h.Urls(urls);
        PageWithCaption(h, Code, "Autumn SALE starts today");
        PageWithCaption(h, Code2, "Behind the scenes", "other_user");
        return h;
    }

    // ---------- G1 syntax ----------

    [Fact]
    public void Fragment_is_part_of_the_url_and_tags_need_a_space()
    {
        var line = Labels.ParseLine(Url + "#frag #sale #video");
        Assert.Equal(Url + "#frag", line.Url);
        Assert.Equal(["sale", "video"], line.Tags);
        Assert.Null(line.Note);
    }

    [Fact]
    public void Note_follows_the_bar_and_is_trimmed()
    {
        var line = Labels.ParseLine(Url + " #a |  first test | still note  ");
        Assert.Equal(Url, line.Url);
        Assert.Equal(["a"], line.Tags);
        Assert.Equal("first test | still note", line.Note);

        Assert.Null(Labels.ParseLine(Url + " |   ").Note);
        Assert.Equal("x", Labels.ParseLine(Url + "|x").Note);
        Assert.Equal(Url, Labels.ParseLine(Url + "|x").Url);
    }

    [Fact]
    public void Long_note_is_cut_to_1000_characters_with_a_warning()
    {
        var warnings = new List<string>();
        var line = Labels.ParseLine(Url + " | " + new string('n', 1500), warnings.Add);
        Assert.Equal(1000, line.Note!.Length);
        Assert.Single(warnings);
    }

    [Fact]
    public void Tags_are_normalized_deduplicated_and_limited()
    {
        var warnings = new List<string>();
        var many = string.Join(' ', Enumerable.Range(1, 25).Select(i => "#t" + i));
        var line = Labels.ParseLine(
            Url + " ##Sale #sale #SALE #cafe\u0301 #" + new string('x', 31) + " #a;b #a,b # " + many + " junk",
            warnings.Add);
        Assert.Equal("Sale", line.Tags[0]); // first spelling kept, compared without case
        Assert.Equal("caf\u00E9", line.Tags[1]); // NFC
        Assert.Equal(20, line.Tags.Count);
        Assert.DoesNotContain(line.Tags, t => t.Contains(';') || t.Contains(',') || t.Length > 30);
        Assert.Contains(warnings, w => w.Contains("more than 20"));
        Assert.Equal(4, warnings.Count(w => w.StartsWith("invalid tag", StringComparison.Ordinal))); // 31 chars, a;b, a,b, "#"
        Assert.Contains(warnings, w => w.Contains("junk"));
    }

    [Fact]
    public void Thirty_characters_count_as_text_elements()
    {
        Assert.NotNull(Labels.NormalizeTag("#" + string.Concat(Enumerable.Repeat("\u7279", 30))));
        Assert.Null(Labels.NormalizeTag("#" + string.Concat(Enumerable.Repeat("\u7279", 31))));
        Assert.NotNull(Labels.NormalizeTag("#" + string.Concat(Enumerable.Repeat("\U0001F600", 30))));
    }

    [Fact]
    public void ReadUrls_keeps_comments_groups_and_the_first_spelling_of_a_tag()
    {
        using var dir = new TempDir();
        File.WriteAllText(dir.File("urls.txt"), string.Join("\n",
            "# comment #not-a-tag",
            "## Client A",
            Url + " #Sale | one",
            Url2 + " #SALE #new"));
        var warnings = new List<string>();
        var urls = new WorkFolder(dir.Path, new RealFileOps(), TimeProvider.System).ReadUrls(warnings.Add);
        Assert.Equal(2, urls.Count);
        Assert.Equal((Url, "Client A", "one"), (urls[0].Url, urls[0].Group, urls[0].Note));
        Assert.Equal(["Sale"], urls[0].Tags);
        Assert.Equal(["Sale", "new"], urls[1].Tags);
        Assert.Empty(warnings);
    }

    [Fact]
    public void Same_post_on_several_lines_gets_union_of_tags_and_first_note()
    {
        using var h = TwoPosts(string.Join("\n",
            Url + " #b",
            "## Other",
            "https://www.threads.com/t/" + Code + " #a #B | first note",
            Url + " #c | second note",
            ""));
        h.Run("run");
        var row = Assert.Single(h.Index(), r => r["shortcode"] == Code);
        Assert.Equal("a;b;c", row["tags"]);
        Assert.Equal("first note", row["note"]);
    }

    // ---------- G2 caption ----------

    [Fact]
    public void Parser_reads_caption_text()
    {
        var parser = new PostPageParser();
        Assert.Equal("Synthetic test post.", parser.Parse(Fixtures.Read("post_ok.html"), Code).Caption);
        var html = Harness.Html(Code, Taken, 1, 1, "example_user", false);
        Assert.Null(parser.Parse(html, Code).Caption);
        Assert.Null(parser.Parse(html.Replace("\"like_count\"", "\"caption\":null,\"like_count\""), Code).Caption);
        Assert.Null(parser.Parse(html.Replace("\"like_count\"", "\"caption\":{\"pk\":\"1\"},\"like_count\""), Code).Caption);
        Assert.Equal("hi", parser.Parse(html.Replace("\"like_count\"",
            "\"caption\":{\"has_translation\":false,\"pk\":\"9\",\"text\":\"hi\",\"text_translation\":null},\"like_count\""), Code).Caption);
    }

    [Fact]
    public void Caption_is_written_only_in_the_first_row_that_has_it()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        PageWithCaption(h, Code, null);
        h.Run("run");
        PageWithCaption(h, Code, "Line one\nLine two");
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        PageWithCaption(h, Code, "Edited later");
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");

        var rows = h.ReadCsv(Folder + "/snapshots.csv");
        Assert.Equal(["", "Line one\nLine two", ""], rows.Select(r => r["caption"]));
        Assert.Equal("Line one Line two", Assert.Single(h.Index())["caption"]);
        var info = File.ReadAllText(h.PathOf(Folder, "info.txt"));
        Assert.Contains("Post text:\r\nLine one\r\nLine two\r\n", info);
    }

    [Fact]
    public void Index_caption_is_one_line_and_cut_to_200_characters()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        PageWithCaption(h, Code, "a\r\nb" + new string('z', 300));
        h.Run("run");
        var caption = Assert.Single(h.Index())["caption"];
        Assert.Equal(201, caption.Length);
        Assert.StartsWith("a b", caption);
        Assert.EndsWith("\u2026", caption);
    }

    [Fact]
    public void Text_that_excel_would_run_as_a_formula_is_quoted_and_read_back()
    {
        using var h = new Harness();
        h.Urls(Url + " | =1+1\n");
        PageWithCaption(h, Code, "=HYPERLINK(\"x\")");
        h.Run("run");
        var index = Assert.Single(h.Index());
        Assert.Equal("'=1+1", index["note"]);
        Assert.StartsWith("'=HYPERLINK", index["caption"]);
        Assert.StartsWith("'=HYPERLINK", Assert.Single(h.ReadCsv(Folder + "/snapshots.csv"))["caption"]);
        h.Run("search", "hyperlink");
        Assert.Contains("post text", h.Text);
    }

    // ---------- G3 outputs ----------

    [Fact]
    public void Index_has_the_new_columns_and_follows_urls_txt()
    {
        using var h = TwoPosts(Url + " #video #Ads | launch post\n" + Url2 + "\n");
        h.Run("run");
        Assert.Equal(["tags", "note", "caption"], FolderStore.IndexColumns[^3..]);
        var first = h.Index().Single(r => r["shortcode"] == Code);
        Assert.Equal("Ads;video", first["tags"]);
        Assert.Equal("launch post", first["note"]);
        Assert.Equal("Autumn SALE starts today", first["caption"]);
        Assert.Equal("", h.Index().Single(r => r["shortcode"] == Code2)["tags"]);
        var info = File.ReadAllText(h.PathOf(Folder, "info.txt"));
        Assert.Contains("Tags: #Ads #video\r\n", info);
        Assert.Contains("My note: launch post\r\n", info);
        Assert.DoesNotContain("tags", File.ReadAllText(h.PathOf(Folder, "snapshots.csv")).Split("\r\n")[0]);

        // Tags removed from urls.txt: the next run updates index.csv and info.txt without a fetch.
        h.Urls(Url + "\n" + Url2 + "\n");
        h.Time.Advance(TimeSpan.FromMinutes(5));
        h.Run("run");
        Assert.DoesNotContain("Fetching", h.Text);
        first = h.Index().Single(r => r["shortcode"] == Code);
        Assert.Equal(("", ""), (first["tags"], first["note"]));
        Assert.DoesNotContain("Tags:", File.ReadAllText(h.PathOf(Folder, "info.txt")));
    }

    [Fact]
    public void Unchanged_info_txt_is_not_rewritten()
    {
        using var h = TwoPosts(Url + " #a\n");
        h.Run("run");
        var info = h.PathOf(Folder, "info.txt");
        var before = File.ReadAllText(info);
        h.Time.Advance(TimeSpan.FromMinutes(5));
        h.Run("run");
        Assert.Equal(before, File.ReadAllText(info));
    }

    // ---------- G4 commands ----------

    private static Harness Tagged()
    {
        var h = TwoPosts(string.Join("\n",
            "## Client A",
            Url + " #sale #video | first test",
            "## Client B",
            Url2 + " #sale",
            ""));
        h.Run("run");
        h.Requests.Clear();
        return h;
    }

    [Fact]
    public void Status_filters_by_tags_with_and_and_by_group()
    {
        using var h = Tagged();
        Assert.Equal(0, h.Run("status", "--tag", "sale"));
        Assert.Contains("2 of 2", h.Text);

        h.Run("status", "--tag", "#SALE", "--tag=video");
        Assert.Contains("1 of 2", h.Text);
        Assert.Contains(Code, h.Text);
        Assert.DoesNotContain(Code2, h.Text);

        h.Run("status", "--group", "client b");
        Assert.Contains(Code2, h.Text);
        Assert.DoesNotContain(Code + " ", h.Text);

        h.Run("status", "--tag", "nothing");
        Assert.Contains("No post matches.", h.Text);
        Assert.Empty(h.Requests);
    }

    [Fact]
    public void Filters_are_rejected_for_other_commands_and_bad_tags()
    {
        using var h = Tagged();
        Assert.Equal(2, h.Run("run", "--tag", "sale"));
        Assert.Equal(2, h.Run("status", "--tag", "a;b"));
        Assert.Equal(2, h.Run("search"));
        Assert.Empty(h.Requests);
    }

    [Theory]
    [InlineData("example_USER", "account")]
    [InlineData("@example", "account")]
    [InlineData("p0st01", "code")]
    [InlineData("#VIDEO", "tag")]
    [InlineData("FIRST TEST", "note")]
    [InlineData("autumn sale", "post text")]
    [InlineData("\uFF21\uFF35\uFF34\uFF35\uFF2D\uFF4E", "post text")] // full-width "AUTUMn"
    public void Search_reports_where_it_matched(string keyword, string field)
    {
        using var h = Tagged();
        Assert.Equal(0, h.Run("search", keyword));
        Assert.Contains("Matched in", h.Text);
        var line = h.Text.Split('\n').Single(l => l.Contains(Code + " ", StringComparison.Ordinal) || l.TrimEnd().EndsWith(field, StringComparison.Ordinal) && l.Contains(Code));
        Assert.Contains(field, line);
        Assert.DoesNotContain(Code2, h.Text);
        Assert.Empty(h.Requests);
    }

    [Fact]
    public void Search_lists_every_matching_field_and_respects_filters()
    {
        using var h = Tagged();
        h.Run("search", "sale");
        Assert.Contains("2 of 2", h.Text);
        var line = h.Text.Split('\n').Single(l => l.Contains(Code + " ", StringComparison.Ordinal));
        Assert.Contains("tag, post text", line);

        h.Run("search", "sale", "--group", "Client B");
        Assert.Contains("1 of 2", h.Text);
        Assert.DoesNotContain(Code + " ", h.Text);

        h.Run("search", "zzz-not-there");
        Assert.Contains("No post matches.", h.Text);
    }

    [Fact]
    public void Search_and_status_speak_chinese()
    {
        using var h = Tagged();
        h.Lang = "zh";
        h.Run("search", "\u7279\u8CE3"); // nothing matches
        Assert.Contains("\u6C92\u6709\u7B26\u5408", h.Text);
        h.Run("search", "sale");
        Assert.Contains("\u7B26\u5408\u65BC", h.Text); // "matched in"
        Assert.Contains("\u6A19\u7C64", h.Text); // "tag"
        h.Run("status", "--tag", "video");
        Assert.Contains("\u7B26\u5408 #video", h.Text);
        h.Run("--help");
        Assert.Contains("search", h.Text);
        Assert.Contains("--tag", h.Text);
    }

    [Fact]
    public void Help_and_template_mention_tags()
    {
        foreach (var s in new[] { Strings.English, Strings.Chinese })
        {
            var help = s.Help(72, "schedule");
            Assert.Contains("search", help);
            Assert.Contains("--tag", help);
            Assert.Contains("--group", help);
            Assert.Contains(" | ", s.UrlsTemplate);
            Assert.Equal(5, s.SearchFields.Length);
        }
    }

    // ---------- G5 compatibility ----------

    [Fact]
    public void Old_snapshots_without_caption_column_still_work()
    {
        using var h = new Harness();
        h.Urls(Url + " #old\n");
        PageWithCaption(h, Code, "Some text");
        h.Run("run");
        // Rewrite the file as the previous release wrote it: no caption column.
        var path = h.PathOf(Folder, "snapshots.csv");
        var rows = h.ReadCsv(Folder + "/snapshots.csv");
        File.WriteAllText(path, "\uFEFF" + Csv.Line(FolderStore.PreviousSnapshotColumns)
            + string.Concat(rows.Select(r => Csv.Line(FolderStore.PreviousSnapshotColumns.Select(c => r[c])))));
        File.WriteAllText(h.PathOf("log.txt"), "");

        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        var text = File.ReadAllText(path);
        Assert.Equal(Csv.Line(FolderStore.PreviousSnapshotColumns), text.TrimStart('\uFEFF').Split("\r\n")[0] + "\r\n");
        Assert.Equal(2, h.ReadCsv(Folder + "/snapshots.csv").Count);
        Assert.DoesNotContain("columns differ", File.ReadAllText(h.PathOf("log.txt")));
        Assert.Equal("old", Assert.Single(h.Index())["tags"]);
        Assert.Equal("", Assert.Single(h.Index())["caption"]);
    }

    [Fact]
    public void Pending_file_with_the_previous_header_is_merged()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        PageWithCaption(h, Code, "Hello");
        h.Run("run");
        var dir = h.PathOf(Folder);
        var row = h.ReadCsv(Folder + "/snapshots.csv")[0];
        row["captured_at_local"] = "2026-10-05T08:30:00+08:00";
        File.WriteAllText(Path.Combine(dir, "snapshots.pending-20261005083000.csv"),
            Csv.Line(FolderStore.PreviousSnapshotColumns) + Csv.Line(FolderStore.PreviousSnapshotColumns.Select(c => row[c])));
        h.Time.Advance(TimeSpan.FromMinutes(40));
        h.Run("run");
        Assert.Empty(Directory.GetFiles(dir, "snapshots.pending-*"));
        var rows = h.ReadCsv(Folder + "/snapshots.csv");
        Assert.Contains(rows, r => r["captured_at_local"] == "2026-10-05T08:30:00+08:00");
        Assert.Equal("Hello", rows[0]["caption"]);
        Assert.Equal("Hello", Assert.Single(h.Index())["caption"]);
    }

    [Fact]
    public void Caption_is_found_in_any_row_and_old_input_keys_still_match()
    {
        using var h = new Harness();
        h.Urls(Url + "\n");
        PageWithCaption(h, Code, "Kept");
        h.Run("run");
        // A tag added later does not change the line's input key: no new folder, no new post.
        h.Urls(Url + " #later | n\n");
        h.Time.Advance(TimeSpan.FromHours(1));
        h.Run("run");
        Assert.Single(h.PostFolders());
        Assert.Equal("later", Assert.Single(h.Index())["tags"]);
        h.Run("search", "kept");
        Assert.Contains("1 of 1", h.Text);
    }
}
