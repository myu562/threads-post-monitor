using System.Globalization;
using System.Text.RegularExpressions;

namespace ThreadsMonitor;

/// <summary>User-facing text. Chinese (Traditional) when the UI culture starts with "zh", else English.</summary>
public static class Text
{
    public static Strings T { get; set; } = Strings.English;

    /// <summary>Picks the language: an explicit --lang value wins, then the UI culture.</summary>
    public static Strings Select(string? lang, CultureInfo uiCulture)
    {
        var name = string.IsNullOrWhiteSpace(lang) ? uiCulture.Name : lang.Trim();
        return name.StartsWith("zh", StringComparison.OrdinalIgnoreCase) ? Strings.Chinese : Strings.English;
    }

    public static string OneLine(string s)
    {
        var one = Redact(Regex.Replace(s, @"\s+", " ").Trim());
        return Limit(one, 500);
    }

    private static readonly Regex UrlRe = new(@"(?:\b[A-Za-z][A-Za-z0-9+.\-]*://|\bwww\.)[^\s""'<>]+", RegexOptions.CultureInvariant);

    /// <summary>
    /// Every error text that goes to a file or the console passes through here: the userinfo of any
    /// URL (scheme://user:password@) becomes ***, and the query string (with anything after it) is removed.
    /// </summary>
    public static string Redact(string s) => UrlRe.Replace(s, m =>
    {
        var u = m.Value;
        var schemeEnd = u.IndexOf("://", StringComparison.Ordinal);
        var start = schemeEnd >= 0 ? schemeEnd + 3 : 0;
        var authorityEnd = u.IndexOfAny(['/', '?', '#'], start);
        if (authorityEnd < 0)
        {
            authorityEnd = u.Length;
        }
        var at = authorityEnd > start ? u.LastIndexOf('@', authorityEnd - 1, authorityEnd - start) : -1;
        if (at >= start)
        {
            u = u[..start] + "***" + u[at..];
        }
        var q = u.IndexOf('?');
        return q >= 0 ? u[..q] : u;
    });

    public static string Limit(string s, int max) => s.Length <= max ? s : s[..(max - 3)] + "...";

    /// <summary>--help in the current language, describing the schedule that is in effect.</summary>
    public static string HelpFor(Settings settings) =>
        T.Help(settings.Tracker.TrackHours, Schedule(T, settings.Tracker.PollBands ?? Core.PollPlanner.DefaultBands, settings.Tracker.TrackHours));

    /// <summary>The fetch schedule in words, generated from the bands (never written by hand).</summary>
    public static string Schedule(Strings t, IReadOnlyList<Core.PollBand> bands, int trackHours)
    {
        var parts = new List<string>();
        var cursor = 0;
        foreach (var b in bands)
        {
            if (b.FromHour >= trackHours)
            {
                break;
            }
            if (b.FromHour > cursor)
            {
                parts.Add(t.ScheduleGap(cursor, b.FromHour, cursor == 0));
            }
            var to = Math.Min(b.ToHour, trackHours);
            parts.Add(t.ScheduleBand(b.FromHour, to, b.IntervalMinutes));
            cursor = to;
        }
        if (cursor < trackHours)
        {
            parts.Add(t.ScheduleGap(cursor, trackHours, cursor == 0));
        }
        return t.SchedulePrefix + string.Join(t.ScheduleSeparator, parts) + t.ScheduleEnd(trackHours);
    }
}

public sealed class Strings
{
    public required string Code { get; init; }
    /// <summary>(track hours, schedule text) -> the --help text.</summary>
    public required Func<int, string, string> Help { get; init; }
    public required string SchedulePrefix { get; init; }
    public required string ScheduleSeparator { get; init; }
    /// <summary>(from hour, to hour, minutes) -> "0-24 h after publishing every 60 minutes".</summary>
    public required Func<int, int, int, string> ScheduleBand { get; init; }
    /// <summary>(from hour, to hour, is the start) -> a stretch without fetches.</summary>
    public required Func<int, int, bool, string> ScheduleGap { get; init; }
    public required Func<int, string> ScheduleEnd { get; init; }
    public required Func<string, string> OptionNeedsValue { get; init; }
    public required string SearchDashHint { get; init; }
    public required Func<int, string, string> UrlsLine { get; init; }
    public required string UrlsCommentedUrl { get; init; }
    public required Func<string, string> UrlsInvalidTag { get; init; }
    public required Func<string, string> UrlsTooManyTags { get; init; }
    public required Func<string, string> UrlsIgnoredText { get; init; }
    public required Func<int, string> UrlsNoteCut { get; init; }
    public required Func<string, string> UrlsNotUtf8 { get; init; }
    public required string LockBusy { get; init; }
    public required Func<string, string> UrlsCreated { get; init; }
    public required Func<string, string> NoUrls { get; init; }
    public required Func<int, string, string> InvalidUrl { get; init; }
    public required Func<string, string, string> ShareFailedFor { get; init; }
    public required string ShareBadFormat { get; init; }
    public required string ShareNetwork { get; init; }
    public required string ShareFailed { get; init; }
    public required string ShareGone { get; init; }
    public required Func<int, int, string, string> Fetching { get; init; }
    public required Func<string, string> FetchFailed { get; init; }
    public required string NothingDue { get; init; }
    public required Func<int, string> RunDone { get; init; }
    public required string PressAnyKey { get; init; }
    public required Func<int, string> SummaryTitle { get; init; }
    public required string NoData { get; init; }
    public required string[] TableHeaders { get; init; }
    public required Func<string, string> StatusName { get; init; }
    public required string DueNow { get; init; }
    public required string RemovedFromUrls { get; init; }
    public required string IndexLocked { get; init; }
    public required string ArchivedNote { get; init; }
    public required string DuplicateNote { get; init; }
    public required string UngroupedFolder { get; init; }
    public required Func<string, string> PathTooLong { get; init; }
    public required string InfoTitle { get; init; }
    public required string InfoAccount { get; init; }
    public required string InfoPublished { get; init; }
    public required string InfoLatest { get; init; }
    public required string InfoFinal { get; init; }
    public required string InfoNote { get; init; }
    public required string InfoCountsHidden { get; init; }
    public required string InfoLastError { get; init; }
    public required string InfoQuotes { get; init; }
    public required string InfoShares { get; init; }
    public required Func<string, string> InfoFooter { get; init; }
    public required Func<string, string> SnapshotsLockedPending { get; init; }
    public required Func<int, string> PendingMerged { get; init; }
    public required string SnapshotsUnreadable { get; init; }
    public required Func<string, string> ScheduleInstalled { get; init; }
    public required string ScheduleUninstalled { get; init; }
    public required Func<string, string> ScheduleFailed { get; init; }
    public required string ScheduleUnsupported { get; init; }
    public required Func<string, string> CronLine { get; init; }
    public required string CronAsk { get; init; }
    public required string CronWritten { get; init; }
    public required string CronSkipped { get; init; }
    public required string CronRemoved { get; init; }
    public required string ScheduleNotFound { get; init; }
    public required Func<string, string> UnknownCommand { get; init; }
    public required string UrlsTemplate { get; init; }
    public required string NetworkDown { get; init; }
    public required string UnreadableNote { get; init; }
    public required Func<string, string> FolderUnreadable { get; init; }
    public required string FutureSnapshots { get; init; }
    public required Func<string, string> LockUnwritable { get; init; }
    public required Func<string, string, string> LockOtherMachine { get; init; }
    public required Func<string, string> ConflictCopies { get; init; }
    public required string InfoTags { get; init; }
    public required string InfoUserNote { get; init; }
    public required string InfoCaption { get; init; }
    public required string FilterOnlyForStatus { get; init; }
    public required Func<string, string> InvalidTagArgument { get; init; }
    public required string SearchNeedsKeyword { get; init; }
    public required Func<string, int, int, string> FilterTitle { get; init; }
    public required Func<string, int, int, string> SearchTitle { get; init; }
    public required string NothingMatches { get; init; }
    public required string MatchedIn { get; init; }
    /// <summary>Names of the searched fields: account, shortcode, tags, note, post text.</summary>
    public required string[] SearchFields { get; init; }
    public required string GroupWord { get; init; }

    private static string Every(int minutes) =>
        minutes % 60 == 0 && minutes > 60 ? $"{minutes / 60} hours" : $"{minutes} minutes";

    private static string EveryZh(int minutes) =>
        minutes % 60 == 0 && minutes > 60 ? $"{minutes / 60} 小時" : $"{minutes} 分鐘";

    public static readonly Strings English = new()
    {
        Code = "en",
        Help = (hours, schedule) => $"""
            ThreadsMonitor - tracks public Threads posts for {hours} hours and writes the numbers to CSV.

            Usage:
              ThreadsMonitor                     Update every tracked post now, show the summary,
                                                 then wait for a key (this is what double-clicking does)
              ThreadsMonitor run                 Fetch only the posts that are due (for the schedule)
              ThreadsMonitor update              Fetch every tracked post now
              ThreadsMonitor status              Show the summary without going online
              ThreadsMonitor status --tag <tag> [--tag <tag>] [--group <name>]
                                                 Only posts with every given tag (and in that group)
              ThreadsMonitor search <words>      Find posts by account, code, tag, note or post text
                                                 (no case, full-width letters match too; offline)
              ThreadsMonitor search -- <words>   The same, for words that start with - (like -5%)
              ThreadsMonitor install-schedule    Run "run" automatically every 10 minutes
              ThreadsMonitor uninstall-schedule  Remove the automatic schedule
              ThreadsMonitor --help              Show this help

            Options:
              --dir <folder>   Work folder (default: the folder that contains this program)
              --lang <en|zh>   Language of the messages (default: the system language)

            Files in the work folder:
              urls.txt        One post URL per line; "## name" starts a group, "# " is a comment.
                              Tags and a note may follow the URL:  <URL> #tag #tag | note
              index.csv       One row per post: latest and {hours}-hour final numbers, tags, note and
                              the start of the post text (rebuilt every run; filter the tags column in Excel)
              <group>/<post>/snapshots.csv   Every fetch of that post, with date and hour
              <group>/<post>/info.txt        A readable card for that post
              log.txt         What happened on each run
              settings.ini    Optional settings
            Move a post folder into a folder named "archive" to make it read-only.

            {schedule}
            The computer has to be on for the schedule to run.
            """,
        SchedulePrefix = "Schedule (settings.ini poll_bands): ",
        ScheduleSeparator = ", ",
        ScheduleBand = (from, to, minutes) => $"{from}-{to} h after publishing every {Every(minutes)}",
        ScheduleGap = (from, to, start) => $"no fetch {from}-{to} h" + (start ? " (except the first fetch when a post is added)" : ""),
        ScheduleEnd = hours => $", and a last fetch at exactly {hours} hours.",
        OptionNeedsValue = option => $"{option} needs a value after it (a value cannot start with --).",
        SearchDashHint = "To search for words that start with -, put -- before them, for example: ThreadsMonitor search -- -5%",
        UrlsLine = (line, msg) => $"urls.txt line {line}: {msg}",
        UrlsCommentedUrl = "this line is a comment, so it is not tracked. Tags go after the URL: <URL> #tag | note",
        UrlsInvalidTag = tag => $"the tag \"{tag}\" was skipped (1-30 characters; no spaces, ; , | or invisible characters)",
        UrlsTooManyTags = tag => $"more than 20 tags on one line; \"{tag}\" was skipped",
        UrlsIgnoredText = text => $"\"{text}\" was ignored (tags start with #, a note goes after |)",
        UrlsNoteCut = max => $"the note is longer than {max} characters; the rest was cut off",
        UrlsNotUtf8 = how => $"urls.txt is not saved as UTF-8; it was read as {how}. Save it as UTF-8 to be sure.",
        LockBusy = "Another ThreadsMonitor is already running in this folder. Skipping this run.",
        UrlsCreated = path => $"Created {path}. Paste one Threads post URL per line, save it, then run again.",
        NoUrls = path => $"No post URLs in {path} yet. Paste one Threads post URL per line, save it, then run again.",
        InvalidUrl = (line, url) => $"urls.txt line {line}: not a Threads post URL, skipped: {url}",
        ShareFailedFor = (url, msg) => $"Could not resolve share link {url}: {msg}",
        ShareBadFormat = "The share link is not in the expected format.",
        ShareNetwork = "Could not reach Threads to resolve the share link. It will be retried next time.",
        ShareFailed = "Could not resolve this share link. Please paste the post URL itself (like https://www.threads.com/@account/post/CODE).",
        ShareGone = "This share link has expired or the post was deleted.",
        Fetching = (i, n, label) => $"[{i}/{n}] {label} ...",
        FetchFailed = err => $"    failed: {err}",
        NothingDue = "Nothing is due right now.",
        RunDone = n => $"Fetched {n} post(s).",
        PressAnyKey = "Press any key to exit.",
        SummaryTitle = n => $"Summary ({n} post(s)) - see index.csv and each post folder's snapshots.csv",
        NoData = "No data yet.",
        TableHeaders = ["#", "Post", "Status", "Hours", "Views", "Likes", "Replies", "Reposts", "Next fetch"],
        StatusName = s => s switch
        {
            "tracking" => "tracking",
            "done" => "done",
            "gone" => "deleted",
            "failed" => "failed",
            _ => s,
        },
        DueNow = "now",
        RemovedFromUrls = "Removed from urls.txt, no longer tracked.",
        IndexLocked = "index.csv is open in another program (Excel?). It will be rebuilt next time. No data was lost.",
        ArchivedNote = "Archived: read-only, no longer fetched.",
        DuplicateNote = "This post is in more than one folder. Delete or archive the extra copy; until then it is not fetched.",
        UngroupedFolder = "Ungrouped",
        PathTooLong = root => $"The folder path is too long. Move the whole folder somewhere shorter (for example D:\\Threads) and try again: {root}",
        InfoTitle = "Threads post (generated by ThreadsMonitor)",
        InfoAccount = "Account",
        InfoPublished = "Published",
        InfoLatest = "Latest",
        InfoFinal = "72-hour final numbers",
        InfoNote = "Note",
        InfoCountsHidden = "the author hides likes and views",
        InfoLastError = "Last error",
        InfoQuotes = "Quotes",
        InfoShares = "Shares",
        InfoFooter = t => $"Updated {t}. This file is rebuilt automatically; the real data is in snapshots.csv.",
        SnapshotsLockedPending = file => $"snapshots.csv is open in another program (Excel?). New rows were saved to {file} and will be merged next time.",
        PendingMerged = n => $"Merged {n} saved file(s) back into snapshots.csv.",
        SnapshotsUnreadable = "Cannot read snapshots.csv. Please close it in Excel and try again.",
        ScheduleInstalled = how => $"Schedule installed: {how}",
        ScheduleUninstalled = "Schedule removed.",
        ScheduleFailed = err => $"Could not change the schedule: {err}",
        ScheduleUnsupported = "Automatic scheduling is not supported on this system.",
        CronLine = line => $"This line runs ThreadsMonitor every 10 minutes:\n\n  {line}\n",
        CronAsk = "Add it to your crontab now? [y/N] ",
        CronWritten = "Added to crontab. Check with: crontab -l",
        CronSkipped = "Not changed. You can add the line yourself with: crontab -e",
        CronRemoved = "Removed from crontab.",
        ScheduleNotFound = "No schedule for this folder was found.",
        UnknownCommand = c => $"Unknown command: {c}",
        NetworkDown = "No network connection. Nothing was counted as a failure; will try again next time.",
        UnreadableNote = "Cannot read snapshots.csv (no shortcode column). This folder is read-only until the file is fixed: save it again as CSV (comma separated) or restore it from a backup.",
        FolderUnreadable = loc => $"Cannot read {loc}/snapshots.csv. That post is not fetched until the file is fixed (see index.csv).",
        FutureSnapshots = "Some snapshots are dated in the future. Please check the computer clock.",
        LockUnwritable = path => $"Cannot write the lock file {path}. Remove its read-only setting (or check the folder permissions) and try again.",
        LockOtherMachine = (host, time) => $"This folder is being used by another computer ({host}, last run {time}). Only one computer may run the schedule for a folder. Remove the schedule on one of them (uninstall-schedule).",
        ConflictCopies = list => $"Found copies made by a sync program (OneDrive/Dropbox): {list}. Please merge them by hand and delete the copies.",
        InfoTags = "Tags",
        InfoUserNote = "My note",
        InfoCaption = "Post text",
        FilterOnlyForStatus = "--tag and --group work only with status and search.",
        InvalidTagArgument = t => $"Not a valid tag: {t} (1-30 characters, no spaces, ; or ,)",
        SearchNeedsKeyword = "search needs a word to look for, for example: ThreadsMonitor search sale",
        FilterTitle = (f, n, total) => $"Posts with {f}: {n} of {total}",
        SearchTitle = (k, n, total) => $"Search \"{k}\": {n} of {total} post(s) match",
        NothingMatches = "No post matches.",
        MatchedIn = "Matched in",
        SearchFields = ["account", "code", "tag", "note", "post text"],
        GroupWord = "group",
        UrlsTemplate = """
            # ThreadsMonitor - list of posts to track
            # Paste one Threads post URL per line, for example:
            #   https://www.threads.com/@example_user/post/DFakeP0st01
            #   https://www.threads.com/t/DFakeP0st01
            #   https://www.threads.com/share/ShareFake01/   (share links work too)
            # A line "## name" starts a group: the posts below it get their folders in a folder
            # with that name. Posts before the first group go to "Ungrouped".
            # Lines starting with "# " are ignored. Delete a line to stop tracking that post
            # (its folder and data stay).
            # Tags and a note may follow the URL after a space, for example:
            #   https://www.threads.com/@example_user/post/DFakeP0st01 #sale #video | first test
            # Filter by tag in Excel (index.csv, tags column) or with: ThreadsMonitor status --tag sale
            # Write tags AFTER the URL: a line that starts with # is a comment and is not tracked.
            #
            # Groups: a line like the one below (without the "# " in front) puts every URL under it
            # into the folder "Client A", until the next "##" line. A line with only "##" ends the group.
            # ## Client A
            # URLs pasted below this line, with no "##" line above them, go to "Ungrouped".

            """,
    };

    public static readonly Strings Chinese = new()
    {
        Code = "zh",
        Help = (hours, schedule) => $"""
            ThreadsMonitor － 追蹤 Threads 公開貼文 {hours} 小時，把數字寫進 CSV。

            用法：
              ThreadsMonitor                     立刻更新所有追蹤中的貼文、印出摘要，
                                                 最後等你按任意鍵（雙擊執行就是這個）
              ThreadsMonitor run                 只抓到期的貼文（給自動排程用）
              ThreadsMonitor update              立刻把所有追蹤中的貼文抓一次
              ThreadsMonitor status              只印摘要，不連網
              ThreadsMonitor status --tag <標籤> [--tag <標籤>] [--group <分組>]
                                                 只列出同時有這些標籤（且在這個分組）的貼文
              ThreadsMonitor search <關鍵字>     用帳號、代碼、標籤、備註或貼文內文找貼文
                                                 （不分大小寫，全形半形都算；不連網）
              ThreadsMonitor search -- <關鍵字>  同上，關鍵字以 - 開頭時用（例如 -5%）
              ThreadsMonitor install-schedule    設定每 10 分鐘自動執行 run
              ThreadsMonitor uninstall-schedule  移除自動排程
              ThreadsMonitor --help              顯示這份說明

            選項：
              --dir <資料夾>   工作資料夾（預設是這個程式所在的資料夾）
              --lang <en|zh>   訊息語言（預設跟隨系統語言）

            工作資料夾裡的檔案：
              urls.txt        一行一個貼文網址；「## 名稱」開始一個分組，「# 」開頭是註解。
                              網址後面可以加標籤和備註：<網址> #標籤 #標籤 | 備註
              index.csv       每篇貼文一列：最新數字、{hours} 小時最終數字、標籤、備註和內文開頭
                              （每次執行重建；可以在 Excel 篩選 tags 欄）
              <分組>/<貼文>/snapshots.csv   這篇貼文的每一次快照，含日期和小時
              <分組>/<貼文>/info.txt        這篇貼文的說明卡
              log.txt         每次執行的紀錄
              settings.ini    選用的設定
            把貼文資料夾拖進名為「封存」的資料夾，就會變成唯讀、不再抓取。

            {schedule}
            電腦要開著，自動排程才會執行。
            """,
        SchedulePrefix = "排程（settings.ini 的 poll_bands）：",
        ScheduleSeparator = "，",
        ScheduleBand = (from, to, minutes) => $"發文後 {from}–{to} 小時每 {EveryZh(minutes)}抓一次",
        ScheduleGap = (from, to, start) => $"{from}–{to} 小時不抓" + (start ? "（加入時的第一次抓取除外）" : ""),
        ScheduleEnd = hours => $"，{hours} 小時整再抓最後一次。",
        OptionNeedsValue = option => $"{option} 後面要接一個值（值不能以 -- 開頭）。",
        SearchDashHint = "要找以 - 開頭的字，請在前面加 --，例如：ThreadsMonitor search -- -5%",
        UrlsLine = (line, msg) => $"urls.txt 第 {line} 行：{msg}",
        UrlsCommentedUrl = "這一行是註解，所以沒有追蹤；標籤要寫在網址後面：<網址> #標籤 | 備註",
        UrlsInvalidTag = tag => $"標籤「{tag}」已略過（1 到 30 字，不能有空白、; , | 或看不見的字元）",
        UrlsTooManyTags = tag => $"一行超過 20 個標籤，「{tag}」已略過",
        UrlsIgnoredText = text => $"「{text}」已忽略（標籤以 # 開頭，備註寫在 | 後面）",
        UrlsNoteCut = max => $"備註超過 {max} 字，後面的部分已截掉",
        UrlsNotUtf8 = how => $"urls.txt 不是用 UTF-8 存檔，已用 {how} 讀取。請另存成 UTF-8 比較保險。",
        LockBusy = "這個資料夾已經有另一個 ThreadsMonitor 在執行，這次略過。",
        UrlsCreated = path => $"已建立 {path}。請在裡面一行貼一個 Threads 貼文網址，存檔後再執行一次。",
        NoUrls = path => $"{path} 裡還沒有貼文網址。請一行貼一個 Threads 貼文網址，存檔後再執行一次。",
        InvalidUrl = (line, url) => $"urls.txt 第 {line} 行不是 Threads 貼文網址，已略過：{url}",
        ShareFailedFor = (url, msg) => $"分享連結 {url} 無法還原：{msg}",
        ShareBadFormat = "分享連結的格式不正確。",
        ShareNetwork = "連不上 Threads，暫時無法還原分享連結，下次執行會再試。",
        ShareFailed = "無法還原這個分享連結，請改貼貼文本身的網址（像 https://www.threads.com/@帳號/post/代碼）。",
        ShareGone = "這個分享連結已失效或貼文已刪除。",
        Fetching = (i, n, label) => $"[{i}/{n}] {label} ...",
        FetchFailed = err => $"    失敗：{err}",
        NothingDue = "目前沒有到期要抓的貼文。",
        RunDone = n => $"已抓取 {n} 篇貼文。",
        PressAnyKey = "按任意鍵結束。",
        SummaryTitle = n => $"摘要（{n} 篇貼文）－ 總表在 index.csv，每篇的快照在各自資料夾的 snapshots.csv",
        NoData = "還沒有資料。",
        TableHeaders = ["#", "貼文", "狀態", "小時", "瀏覽", "讚", "回覆", "轉發", "下次抓取"],
        StatusName = s => s switch
        {
            "tracking" => "追蹤中",
            "done" => "完成",
            "gone" => "已刪除",
            "failed" => "失敗",
            _ => s,
        },
        DueNow = "現在",
        RemovedFromUrls = "已從 urls.txt 移除，不再追蹤。",
        IndexLocked = "index.csv 正被其他程式（Excel？）開啟，下次執行會重建。資料沒有遺失。",
        ArchivedNote = "已封存：唯讀，不再抓取。",
        DuplicateNote = "這篇貼文出現在不只一個資料夾。請刪掉或封存多出來的那份；在那之前不會抓取。",
        UngroupedFolder = "未分組",
        PathTooLong = root => $"資料夾路徑太長。請把整個資料夾搬到比較短的位置（例如 D:\\Threads）再試一次：{root}",
        InfoTitle = "Threads 貼文（由 ThreadsMonitor 產生）",
        InfoAccount = "帳號",
        InfoPublished = "發布時間",
        InfoLatest = "最新快照",
        InfoFinal = "72 小時最終數字",
        InfoNote = "備註",
        InfoCountsHidden = "作者隱藏了按讚數和瀏覽數",
        InfoLastError = "最後錯誤",
        InfoQuotes = "引用",
        InfoShares = "分享",
        InfoFooter = t => $"更新於 {t}。這個檔案會自動重建，真正的資料在 snapshots.csv。",
        SnapshotsLockedPending = file => $"snapshots.csv 正被其他程式（Excel？）開啟。這次的快照先存到 {file}，下次執行會合併回去。",
        PendingMerged = n => $"已把 {n} 個暫存檔合併回 snapshots.csv。",
        SnapshotsUnreadable = "無法讀取 snapshots.csv，請先在 Excel 關閉它再試一次。",
        ScheduleInstalled = how => $"已設定自動排程：{how}",
        ScheduleUninstalled = "已移除自動排程。",
        ScheduleFailed = err => $"無法變更排程：{err}",
        ScheduleUnsupported = "這個系統不支援自動排程。",
        CronLine = line => $"下面這一行會每 10 分鐘執行一次 ThreadsMonitor：\n\n  {line}\n",
        CronAsk = "要現在寫入 crontab 嗎？[y/N] ",
        CronWritten = "已寫入 crontab。可以用 crontab -l 確認。",
        CronSkipped = "沒有變更。你也可以用 crontab -e 自己加上這一行。",
        CronRemoved = "已從 crontab 移除。",
        ScheduleNotFound = "找不到這個資料夾的自動排程。",
        UnknownCommand = c => $"不認得的指令：{c}",
        NetworkDown = "網路無法連線，下次再試。這次不算失敗。",
        UnreadableNote = "無法讀取 snapshots.csv（找不到 shortcode 欄位）。修好之前這個資料夾是唯讀：請用 Excel 另存成 CSV（逗號分隔），或從備份還原。",
        FolderUnreadable = loc => $"無法讀取 {loc}/snapshots.csv。修好之前這篇貼文不會抓取（詳見 index.csv）。",
        FutureSnapshots = "有時間在未來的快照，請檢查電腦時間。",
        LockUnwritable = path => $"無法寫入鎖定檔 {path}。請取消它的唯讀設定（或檢查資料夾權限）後再試一次。",
        LockOtherMachine = (host, time) => $"這個資料夾正被另一台電腦使用（{host}，最後執行於 {time}）。同一個資料夾只能讓一台電腦跑排程，請在其中一台執行 uninstall-schedule。",
        ConflictCopies = list => $"發現同步軟體（OneDrive／Dropbox）產生的衝突副本：{list}。請手動合併後刪除副本。",
        InfoTags = "標籤",
        InfoUserNote = "我的備註",
        InfoCaption = "貼文內文",
        FilterOnlyForStatus = "--tag 和 --group 只能搭配 status 或 search 使用。",
        InvalidTagArgument = t => $"不是有效的標籤：{t}（1 到 30 字，不能有空白、; 或 ,）",
        SearchNeedsKeyword = "search 後面要接要找的字，例如：ThreadsMonitor search 特賣",
        FilterTitle = (f, n, total) => $"符合 {f} 的貼文：{n} 篇（共 {total} 篇）",
        SearchTitle = (k, n, total) => $"搜尋「{k}」：{n} 篇符合（共 {total} 篇）",
        NothingMatches = "沒有符合的貼文。",
        MatchedIn = "符合於",
        SearchFields = ["帳號", "代碼", "標籤", "備註", "內文"],
        GroupWord = "分組",
        UrlsTemplate = """
            # ThreadsMonitor 要追蹤的貼文清單
            # 一行貼一個 Threads 貼文網址，例如：
            #   https://www.threads.com/@example_user/post/DFakeP0st01
            #   https://www.threads.com/t/DFakeP0st01
            #   https://www.threads.com/share/ShareFake01/   （分享連結也可以）
            # 「## 名稱」這一行開始一個分組：它下面的貼文，資料夾會建在叫這個名稱的資料夾裡。
            # 第一個分組之前的貼文放進「未分組」。
            # 「# 」開頭的行會被忽略。刪掉某一行＝停止追蹤那篇貼文（資料夾和資料都會保留）。
            # 網址後面空一格可以加標籤和備註，例如：
            #   https://www.threads.com/@example_user/post/DFakeP0st01 #特賣 #影片 | 第一次測試
            # 用標籤篩選：在 Excel 篩選 index.csv 的 tags 欄，或執行 ThreadsMonitor status --tag 特賣
            # 標籤要寫在網址「後面」：以 # 開頭的行是註解，不會追蹤。
            #
            # 分組：像下面這樣的一行（把前面的「# 」拿掉）會讓它下面的網址都放進「客戶A」資料夾，
            # 直到下一個「##」行為止。只寫「##」的一行會結束分組。
            # ## 客戶A
            # 貼在這一行下面、上方沒有「##」行的網址，會放進「未分組」。

            """,
    };
}
