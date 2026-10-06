using System.Globalization;
using System.Text;
using ThreadsMonitor.Core;
using ThreadsMonitor.Store;

namespace ThreadsMonitor;

/// <summary>Everything the app touches outside itself, so tests can replace it.</summary>
public sealed class AppEnvironment
{
    public required TextWriter Out { get; init; }
    public required TimeProvider Time { get; init; }
    public required IFileOps Files { get; init; }
    public required string BaseDirectory { get; init; }
    public required string ExePath { get; init; }
    public required OsKind Os { get; init; }
    public required string HomeDirectory { get; init; }
    public required Func<Settings, ThreadsHttp> HttpFactory { get; init; }
    public required IProcessRunner Processes { get; init; }
    public CultureInfo UiCulture { get; init; } = CultureInfo.CurrentUICulture;
    public bool Interactive { get; init; }
    public Action WaitForKey { get; init; } = () => { };
    public Func<string?> ReadLine { get; init; } = () => null;
    public Action<TimeSpan> Sleep { get; init; } = Thread.Sleep;

    /// <summary>This computer's name (written into .lock, recognized in sync conflict copies).</summary>
    public string MachineName { get; init; } = Environment.MachineName;
    public int ProcessId { get; init; } = Environment.ProcessId;

    /// <summary>Longest path allowed for files the program creates (applied on Windows only).</summary>
    public int MaxPathLength { get; init; } = Naming.MaxPathLength;

    /// <summary>The Windows ANSI code page, used for a urls.txt that is not UTF-8 (null elsewhere).</summary>
    public int? AnsiCodePage { get; init; }

    /// <summary>Creates the data store for a work folder (directory, environment, log).</summary>
    public Func<string, AppEnvironment, Action<string>, IDataStore> StoreFactory { get; init; } =
        (dir, env, log) => new FolderStore(dir, env.Files, env.Time, env.Sleep, log, Text.T.UngroupedFolder,
            windowsPaths: env.Os == OsKind.Windows, hostNames: [env.MachineName], maxPathLength: env.MaxPathLength);
}

public enum FetchMode
{
    DueOnly,
    All,
}

public sealed class App(AppEnvironment env)
{
    private TextWriter Out => env.Out;
    private static Strings T => Text.T;

    public async Task<int> RunAsync(string[] args)
    {
        string? command = null;
        string? dir = null;
        string? lang = null;
        var help = false;
        string? bad = null;
        var tags = new List<string>();
        string? group = null;
        var words = new List<string>();
        var filtered = false;

        string? missing = null;
        var endOfOptions = false;

        // Takes the value of an option; a missing value (or one starting with --, which is the next
        // option) is an error instead of silently using the next option as the value.
        void Set(string option, string value)
        {
            if (value.StartsWith("--", StringComparison.Ordinal) || (option == "--group" && string.IsNullOrWhiteSpace(value)))
            {
                missing ??= option;
                return;
            }
            switch (option)
            {
                case "--dir":
                    dir = value;
                    break;
                case "--lang":
                    lang = value;
                    break;
                case "--tag":
                    tags.Add(Labels.StripInvisible(value));
                    filtered = true;
                    break;
                case "--group":
                    group = Labels.StripInvisible(value);
                    filtered = true;
                    break;
            }
        }

        for (var i = 0; i < args.Length; i++)
        {
            var a = args[i];
            if (endOfOptions)
            {
                if (command == null)
                {
                    command = a;
                }
                else if (command == "search")
                {
                    words.Add(a);
                }
                else
                {
                    bad ??= a;
                }
                continue;
            }
            if (a == "--")
            {
                endOfOptions = true; // everything after -- is a word, even when it starts with -
                continue;
            }
            if (a is "--help" or "-h" or "/?" or "help")
            {
                help = true;
                continue;
            }
            if (a is "--dir" or "--lang" or "--tag" or "--group")
            {
                if (i + 1 < args.Length)
                {
                    Set(a, args[++i]);
                }
                else
                {
                    missing ??= a;
                }
                continue;
            }
            var eq = a.IndexOf('=');
            if (eq > 0 && a[..eq] is "--dir" or "--lang" or "--tag" or "--group")
            {
                Set(a[..eq], a[(eq + 1)..]);
                continue;
            }
            if (command == null && !a.StartsWith('-'))
            {
                command = a;
            }
            else if (command == "search" && (!a.StartsWith('-') || words.Count > 0))
            {
                words.Add(a); // a keyword may itself start with - after the first word
            }
            else
            {
                bad ??= a;
            }
        }

        Text.T = Text.Select(lang, env.UiCulture);

        var workDir = Path.GetFullPath(string.IsNullOrWhiteSpace(dir) ? env.BaseDirectory : dir);

        if (help)
        {
            Out.WriteLine(HelpText(workDir));
            return 0;
        }
        if (missing != null)
        {
            Out.WriteLine(T.OptionNeedsValue(missing));
            return 2;
        }
        if (bad != null || command is not (null or "run" or "update" or "status" or "search" or "install-schedule" or "uninstall-schedule"))
        {
            Out.WriteLine(T.UnknownCommand(bad ?? command!));
            if (command == "search" && bad != null && bad.StartsWith('-'))
            {
                Out.WriteLine(T.SearchDashHint);
            }
            Out.WriteLine();
            Out.WriteLine(HelpText(workDir));
            return 2;
        }
        if (filtered && command is not ("status" or "search"))
        {
            Out.WriteLine(T.FilterOnlyForStatus);
            return 2;
        }
        var filter = new PostFilter(tags, group);
        if (filter.InvalidTag is { } invalidTag)
        {
            Out.WriteLine(T.InvalidTagArgument(invalidTag));
            return 2;
        }
        // Zero-width and other invisible characters (copied from a chat app) would never match.
        var keyword = Labels.StripInvisible(string.Join(' ', words)).Trim();
        if (command == "search" && keyword.Length == 0)
        {
            Out.WriteLine(T.SearchNeedsKeyword);
            Out.WriteLine();
            Out.WriteLine(HelpText(workDir));
            return 2;
        }

        switch (command)
        {
            case "status":
                return Status(workDir, filter);
            case "search":
                return Search(workDir, filter, keyword);
            case "install-schedule":
            case "uninstall-schedule":
                return Schedule(workDir, dir != null, command == "install-schedule");
            case "run":
                // "run" without a console is the schedule: messages go to log.txt only.
                return await FetchAsync(workDir, FetchMode.DueOnly, quiet: !env.Interactive).ConfigureAwait(false);
            case "update":
                return await FetchAsync(workDir, FetchMode.All).ConfigureAwait(false);
            default:
                var code = await FetchAsync(workDir, FetchMode.All).ConfigureAwait(false);
                if (env.Interactive)
                {
                    Out.WriteLine();
                    Out.WriteLine(T.PressAnyKey);
                    env.WaitForKey();
                }
                return code;
        }
    }

    /// <summary>--help for the settings.ini of the work folder (the defaults when it cannot be read).</summary>
    private string HelpText(string workDir)
    {
        Settings settings;
        try
        {
            settings = new WorkFolder(workDir, env.Files, env.Time, env.AnsiCodePage).LoadSettings();
        }
        catch (Exception e) when (e is IOException or UnauthorizedAccessException)
        {
            settings = new Settings();
        }
        return Text.HelpFor(settings);
    }

    // ---------- schedule ----------

    private int Schedule(string workDir, bool customDir, bool install)
    {
        var installer = new ScheduleInstaller(env.Processes, env.Files, env.Os, env.ExePath, workDir, customDir,
            env.HomeDirectory, Out, env.ReadLine);
        try
        {
            return install ? installer.Install() : installer.Uninstall();
        }
        catch (Exception e) when (e is not OutOfMemoryException)
        {
            Out.WriteLine(T.ScheduleFailed(Text.OneLine(e.Message)));
            return 1;
        }
    }

    // ---------- status ----------

    private int Status(string workDir, PostFilter filter)
    {
        var all = OfflineIndex(workDir);
        var entries = all.Where(filter.Matches).ToList();
        if (filter.IsEmpty)
        {
            PrintTable(entries);
            return 0;
        }

        Out.WriteLine(T.FilterTitle(filter.Describe(), entries.Count, all.Count));
        if (entries.Count == 0)
        {
            Out.WriteLine(T.NothingMatches);
            return 0;
        }
        PrintRows(entries, null);
        return 0;
    }

    // ---------- search ----------

    private int Search(string workDir, PostFilter filter, string keyword)
    {
        var all = OfflineIndex(workDir);
        var needle = Labels.Fold(keyword);
        var hits = new List<(IndexEntry Entry, string Where)>();
        foreach (var e in all.Where(filter.Matches))
        {
            var m = e.Post.Meta;
            var where = new List<string>();
            if (m.Username != null && Labels.Contains("@" + m.Username, needle))
            {
                where.Add(T.SearchFields[0]);
            }
            if (Labels.Contains(m.Shortcode, needle))
            {
                where.Add(T.SearchFields[1]);
            }
            if (e.Tags.Any(t => Labels.Contains("#" + t, needle)))
            {
                where.Add(T.SearchFields[2]);
            }
            if (Labels.Contains(e.UserNote, needle))
            {
                where.Add(T.SearchFields[3]);
            }
            if (Labels.Contains(m.Caption, needle))
            {
                where.Add(T.SearchFields[4]);
            }
            if (where.Count > 0)
            {
                hits.Add((e, string.Join(", ", where)));
            }
        }

        Out.WriteLine(T.SearchTitle(keyword, hits.Count, all.Count));
        if (hits.Count == 0)
        {
            Out.WriteLine(T.NothingMatches);
            return 0;
        }
        PrintRows(hits.Select(h => h.Entry).ToList(), hits.Select(h => h.Where).ToList());
        return 0;
    }

    /// <summary>The index as it is on disk now, with urls.txt applied, without going online.</summary>
    private IReadOnlyList<IndexEntry> OfflineIndex(string workDir)
    {
        var folder = new WorkFolder(workDir, env.Files, env.Time, env.AnsiCodePage);
        var settings = folder.LoadSettings(m =>
        {
            folder.Log(m);
            Out.WriteLine(m);
        });
        var store = env.StoreFactory(workDir, env, _ => { });
        var posts = store.LoadPosts();
        var session = new Session(posts, new Tracker(settings.Tracker), env.Time.GetUtcNow());

        foreach (var u in folder.ReadUrls())
        {
            string? code = null;
            if (session.Lookup(u.Url) is { } known)
            {
                code = known;
                session.Activate(u.Url, code, u.Group, null);
            }
            else if (ThreadsUrl.Parse(u.Url) is { } p)
            {
                code = p.Shortcode;
                session.Activate(u.Url, code, u.Group, p.Username);
            }
            if (code != null)
            {
                session.AddLabels(code, u.Tags, u.Note);
            }
        }

        return session.BuildIndex(posts);
    }

    // ---------- run / update ----------

    private async Task<int> FetchAsync(string workDir, FetchMode mode, bool quiet = false)
    {
        env.Files.CreateDirectory(workDir);
        var folder = new WorkFolder(workDir, env.Files, env.Time, env.AnsiCodePage);

        var acquired = folder.Acquire(env.MachineName, env.ProcessId);
        switch (acquired.State)
        {
            case LockState.Busy:
                Out.WriteLine(T.LockBusy);
                return 0;
            case LockState.Unwritable:
                Out.WriteLine(T.LockUnwritable(folder.LockPath));
                folder.Log("cannot write .lock");
                return 1;
            case LockState.OtherComputer:
                var when = TimeZoneInfo.ConvertTime(acquired.OtherTime!.Value, env.Time.LocalTimeZone)
                    .ToString("yyyy-MM-dd HH:mm", CultureInfo.InvariantCulture);
                Out.WriteLine(T.LockOtherMachine(acquired.OtherHost!, when));
                folder.Log($"refused: folder used by another computer ({acquired.OtherHost}) at {when}");
                return 1;
        }
        using var runLock = acquired.Handle;

        // Problems in settings.ini and urls.txt go to log.txt, and also to the console unless this
        // is a scheduled run (nobody reads that console; cron would mail it).
        Action<string>? show = quiet ? null : Out.WriteLine;
        var settings = folder.LoadSettings(m =>
        {
            folder.Log(m);
            show?.Invoke(m);
        });
        if (folder.EnsureUrlsFile(T.UrlsTemplate))
        {
            Out.WriteLine(T.UrlsCreated(folder.UrlsPath));
            folder.Log("created urls.txt");
            return 0;
        }

        var store = env.StoreFactory(workDir, env, folder.Log);
        if (store is FolderStore fs)
        {
            fs.AddHostName(acquired.PreviousHost);
        }
        var posts = store.LoadPosts();
        var merged = store.MergePending();
        if (merged > 0)
        {
            Out.WriteLine(T.PendingMerged(merged));
            folder.Log($"merged {merged} pending snapshot file(s)");
            posts = store.LoadPosts();
        }

        foreach (var w in store.Warnings.Distinct(StringComparer.Ordinal))
        {
            Out.WriteLine(w);
            folder.Log(w);
        }

        var urls = folder.ReadUrls(folder.Log, show);
        if (urls.Count == 0 && posts.Count == 0)
        {
            Out.WriteLine(T.NoUrls(folder.UrlsPath));
            return 0;
        }

        var tracker = new Tracker(settings.Tracker);
        var session = new Session(posts, tracker, env.Time.GetUtcNow());
        var offline = false;
        using var http = new LazyHttp(() => env.HttpFactory(settings));
        var pacer = new Pacer(env.Sleep, TimeSpan.FromSeconds(settings.SleepSeconds));

        // 1. Map urls.txt lines to shortcodes. A share link is resolved once; the mapping is then
        //    remembered in the post's data (input_key, a hash of the share code).
        var seen = new Dictionary<string, string?>(StringComparer.Ordinal);
        var lineCode = new Dictionary<string, string>(StringComparer.Ordinal);
        var labelsLater = new List<UrlEntry>();
        foreach (var u in urls)
        {
            if (seen.TryGetValue(u.Url, out var firstGroup))
            {
                if (!string.Equals(firstGroup, u.Group, StringComparison.Ordinal))
                {
                    folder.Log($"urls.txt line {u.Line}: URL already listed under another group; the first one is used");
                }
                labelsLater.Add(u); // its tags and note still count
                continue;
            }
            seen[u.Url] = u.Group;

            if (session.Lookup(u.Url) is { } known)
            {
                session.Activate(u.Url, known, u.Group, null);
                lineCode[u.Url] = known;
                session.AddLabels(known, u.Tags, u.Note);
                continue;
            }
            if (ThreadsUrl.Parse(u.Url) is { } parsed)
            {
                session.Activate(u.Url, parsed.Shortcode, u.Group, parsed.Username);
                lineCode[u.Url] = parsed.Shortcode;
                session.AddLabels(parsed.Shortcode, u.Tags, u.Note);
                continue;
            }
            if (ThreadsUrl.ParseShare(u.Url) is { } shareCode)
            {
                if (offline)
                {
                    continue;
                }
                pacer.Wait();
                try
                {
                    var resolved = ThreadsUrl.Parse(await new ShareUrlResolver(http.Value).ResolveAsync(shareCode).ConfigureAwait(false))!;
                    session.Activate(u.Url, resolved.Shortcode, u.Group, resolved.Username);
                    lineCode[u.Url] = resolved.Shortcode;
                    session.AddLabels(resolved.Shortcode, u.Tags, u.Note);
                    folder.Log($"share link on line {u.Line} resolved");
                }
                catch (ShareResolveException e)
                {
                    Out.WriteLine(T.ShareFailedFor(Text.Redact(u.Url), e.Message));
                    folder.Log($"share link on line {u.Line} not resolved: {e.Message}");
                    offline = e.IsNetwork;
                }
                continue;
            }

            Out.WriteLine(T.InvalidUrl(u.Line, Text.Redact(u.Url)));
            folder.Log($"urls.txt line {u.Line}: not a Threads post URL");
        }
        foreach (var u in labelsLater)
        {
            if (lineCode.TryGetValue(u.Url, out var code))
            {
                session.AddLabels(code, u.Tags, u.Note);
            }
        }

        // 2. Fetch: posts in urls.txt that have no data yet, or that are writable, still tracking
        //    and due (always "due" for a manual update).
        var targets = offline ? [] : session.Targets(mode, env.Time.GetUtcNow());
        targets = targets.Where(code =>
        {
            var (_, g) = session.Input(code);
            if (store.CannotStore(code, session.Meta(code), g) is not { } reason)
            {
                return true;
            }
            Out.WriteLine($"{Label(session.Meta(code))}: {Text.OneLine(reason)}");
            return false;
        }).ToList();
        var fetcher = targets.Count > 0 ? new PublicPageFetcher(http.Value, new PostPageParser()) : null;
        var fetched = 0;
        for (var i = 0; i < targets.Count; i++)
        {
            var code = targets[i];
            var meta = session.Meta(code);
            pacer.Wait();
            fetched++;
            Out.WriteLine(T.Fetching(i + 1, targets.Count, Label(meta)));

            FetchResult result;
            try
            {
                result = await fetcher!.FetchAsync(code).ConfigureAwait(false);
            }
            catch (Exception e) when (e is not OutOfMemoryException)
            {
                result = FetchResult.Failure(FetchStatus.Exception, null, Text.OneLine(e.GetType().Name + ": " + e.Message));
            }

            var snapshot = session.Record(code, result, Truncate(env.Time.GetUtcNow()));
            var (inputKey, group) = session.Input(code);
            try
            {
                store.AppendSnapshot(snapshot, meta, inputKey, group);
            }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException or InvalidOperationException)
            {
                folder.Log($"{code}: could not save snapshot: {e.Message}");
                Out.WriteLine("    " + Text.OneLine(e.Message));
            }

            if (result.IsOk)
            {
                folder.Log($"{code}: ok views={result.Data!.Views?.ToString(CultureInfo.InvariantCulture) ?? "-"}");
            }
            else
            {
                Out.WriteLine(T.FetchFailed(Text.OneLine($"[{result.Status}] {result.Error}")));
                folder.Log($"{code}: [{result.Status}] {result.Error} {result.Excerpt}");
            }

            if (i == 0 && result.Status == FetchStatus.NetworkError)
            {
                // The very first request could not reach anything: this computer is offline. Do not
                // try the rest now (nothing is counted as failed); the next run tries again.
                offline = true;
                break;
            }
        }

        if (offline)
        {
            Out.WriteLine(T.NetworkDown);
            folder.Log("network unreachable; the remaining posts are tried next time");
        }

        foreach (var pending in store.PendingWrites)
        {
            Out.WriteLine(T.SnapshotsLockedPending(pending));
            folder.Log("snapshots.csv locked; rows saved to " + pending);
        }

        // 3. Rebuild the index from what is on disk now.
        var entries = session.BuildIndex(store.LoadPosts());
        if (!store.WriteIndex(entries, env.Time.GetUtcNow()))
        {
            Out.WriteLine(T.IndexLocked);
        }

        folder.Log($"{(mode == FetchMode.All ? "update" : "run")}: fetched {fetched}");
        if (!offline)
        {
            Out.WriteLine(fetched == 0 && mode == FetchMode.DueOnly ? T.NothingDue : T.RunDone(fetched));
        }

        if (mode == FetchMode.All)
        {
            Out.WriteLine();
            PrintTable(entries);
        }

        return 0;
    }

    private static DateTimeOffset Truncate(DateTimeOffset t) =>
        new(t.Ticks - t.Ticks % TimeSpan.TicksPerSecond, t.Offset);

    private static string Label(PostMeta m) => m.Username != null ? $"@{m.Username} {m.Shortcode}" : m.Shortcode;

    // ---------- table ----------

    private void PrintTable(IReadOnlyList<IndexEntry> entries)
    {
        Out.WriteLine(T.SummaryTitle(entries.Count));
        if (entries.Count == 0)
        {
            Out.WriteLine(T.NoData);
            return;
        }
        PrintRows(entries, null);
    }

    /// <summary>The summary table; <paramref name="matched"/> adds a last column (search: where it matched).</summary>
    private void PrintRows(IReadOnlyList<IndexEntry> entries, IReadOnlyList<string>? matched)
    {
        var now = env.Time.GetUtcNow();
        var rows = new List<string[]> { matched == null ? T.TableHeaders : [.. T.TableHeaders, T.MatchedIn] };
        var n = 0;
        foreach (var e in entries)
        {
            var s = e.State;
            var last = s.LastOk;
            string next = e.Note != null || e.Post.ReadOnly || s.NextDue == null
                ? "-"
                : s.NextDue == DateTimeOffset.MinValue || s.NextDue <= now
                    ? T.DueNow
                    : TimeZoneInfo.ConvertTime(s.NextDue.Value, env.Time.LocalTimeZone).ToString("MM-dd HH:mm", CultureInfo.InvariantCulture);
            string[] row =
            [
                (n + 1).ToString(CultureInfo.InvariantCulture),
                Label(e.Post.Meta),
                T.StatusName(s.Status) + (e.Post.Archived ? "*" : ""),
                last?.HoursSincePublish?.ToString("0.0", CultureInfo.InvariantCulture) ?? "-",
                Num(last?.Views), Num(last?.Likes), Num(last?.Replies), Num(last?.Reposts),
                next,
            ];
            rows.Add(matched == null ? row : [.. row, matched[n]]);
            n++;
        }

        var widths = new int[rows[0].Length];
        foreach (var r in rows)
        {
            for (var i = 0; i < r.Length; i++)
            {
                widths[i] = Math.Max(widths[i], DisplayWidth(r[i]));
            }
        }

        foreach (var r in rows)
        {
            var sb = new StringBuilder();
            for (var i = 0; i < r.Length; i++)
            {
                var pad = widths[i] - DisplayWidth(r[i]);
                if (i is 0 or >= 3 and <= 7)
                {
                    sb.Append(' ', pad).Append(r[i]);
                }
                else
                {
                    sb.Append(r[i]).Append(' ', pad);
                }
                sb.Append("  ");
            }
            Out.WriteLine(sb.ToString().TrimEnd());
        }
    }

    private static string Num(long? v) => v?.ToString("N0", CultureInfo.InvariantCulture) ?? "-";

    public static int DisplayWidth(string s)
    {
        var w = 0;
        foreach (var c in s)
        {
            w += c is >= 'ᄀ' and <= 'ᅟ' or >= '⺀' and <= '꓏' or >= '가' and <= '힣'
                or >= '豈' and <= '﫿' or >= '︰' and <= '﹏' or >= '＀' and <= '｠'
                or >= '￠' and <= '￦'
                ? 2
                : 1;
        }
        return w;
    }

    // ---------- helpers ----------

    private sealed class LazyHttp(Func<ThreadsHttp> factory) : IDisposable
    {
        private ThreadsHttp? _value;

        public ThreadsHttp Value => _value ??= factory();

        public void Dispose() => _value?.Dispose();
    }

    /// <summary>Sleeps between two consecutive requests (never before the first one).</summary>
    private sealed class Pacer(Action<TimeSpan> sleep, TimeSpan gap)
    {
        private bool _started;

        public void Wait()
        {
            if (_started && gap > TimeSpan.Zero)
            {
                sleep(gap);
            }
            _started = true;
        }
    }
}

/// <summary>
/// In-memory view of one run: which posts urls.txt asks for, their metadata and snapshots.
/// Pure bookkeeping; no IO.
/// </summary>
public sealed class Session
{
    private readonly Tracker _tracker;
    private readonly DateTimeOffset? _now;
    private readonly Dictionary<string, StoredPost> _stored = new(StringComparer.Ordinal);
    private readonly Dictionary<string, string> _keyToCode = new(StringComparer.Ordinal);
    private readonly Dictionary<string, PostMeta> _metas = new(StringComparer.Ordinal);
    private readonly Dictionary<string, List<Snapshot>> _snapshots = new(StringComparer.Ordinal);
    private readonly Dictionary<string, (string? InputKey, string? Group)> _active = new(StringComparer.Ordinal);
    private readonly Dictionary<string, List<string>> _tags = new(StringComparer.Ordinal);
    private readonly Dictionary<string, string> _notes = new(StringComparer.Ordinal);
    private readonly List<string> _activeOrder = [];

    /// <param name="now">The time of this run: snapshots dated more than a day later are ignored (wrong clock).</param>
    public Session(IReadOnlyList<StoredPost> posts, Tracker tracker, DateTimeOffset? now = null)
    {
        _tracker = tracker;
        _now = now;
        foreach (var p in posts)
        {
            _stored[p.Shortcode] = p;
            _metas[p.Shortcode] = p.Meta;
            _snapshots[p.Shortcode] = p.Snapshots.ToList();
            foreach (var key in p.InputKeys)
            {
                _keyToCode.TryAdd(key, p.Shortcode);
            }
        }
    }

    /// <summary>The shortcode a urls.txt line was mapped to before (for share links), or null.</summary>
    public string? Lookup(string inputUrl) => InputKey.Of(inputUrl) is { } key ? _keyToCode.GetValueOrDefault(key) : null;

    /// <summary>Marks a post as wanted by urls.txt. The first input line / group for a post wins.</summary>
    public void Activate(string inputUrl, string shortcode, string? group, string? username)
    {
        var inputKey = InputKey.Of(inputUrl);
        if (inputKey != null)
        {
            _keyToCode.TryAdd(inputKey, shortcode);
        }
        if (!_metas.TryGetValue(shortcode, out var meta))
        {
            DateTimeOffset? published = null;
            try
            {
                published = Shortcode.CreatedAt(shortcode) is { } c ? DateTimeOffset.FromUnixTimeSeconds(c.ToUnixTimeSeconds()) : null;
            }
            catch (ArgumentException)
            {
            }
            meta = new PostMeta
            {
                Shortcode = shortcode,
                Username = username,
                PublishedAt = published,
                PublishedAtSource = published != null ? PublishedSource.Shortcode : null,
            };
            _metas[shortcode] = meta;
            _snapshots[shortcode] = [];
        }
        else if (meta.Username == null && username != null)
        {
            meta.Username = username;
        }

        if (_active.TryAdd(shortcode, (inputKey, group)))
        {
            _activeOrder.Add(shortcode);
        }
    }

    /// <summary>
    /// Adds the tags and note of one urls.txt line. A post listed on several lines gets the union of
    /// their tags (compared without case, first spelling kept) and the first non-empty note.
    /// </summary>
    public void AddLabels(string shortcode, IReadOnlyList<string> tags, string? note)
    {
        if (tags.Count > 0)
        {
            if (!_tags.TryGetValue(shortcode, out var list))
            {
                _tags[shortcode] = list = [];
            }
            foreach (var t in tags)
            {
                if (!list.Contains(t, Labels.TagComparer))
                {
                    list.Add(t);
                }
            }
        }
        if (!string.IsNullOrEmpty(note))
        {
            _notes.TryAdd(shortcode, note);
        }
    }

    public IReadOnlyList<string> Tags(string shortcode) => _tags.TryGetValue(shortcode, out var t) ? t : [];

    public PostMeta Meta(string shortcode) => _metas[shortcode];

    public (string? InputKey, string? Group) Input(string shortcode) => _active[shortcode];

    public PostState Evaluate(string shortcode) => _tracker.Evaluate(_metas[shortcode], _snapshots[shortcode], _now);

    /// <summary>
    /// Posts to fetch: in urls.txt, and either without any data yet, or writable, still tracking and
    /// due (a manual update treats every tracking post as due).
    /// </summary>
    public List<string> Targets(FetchMode mode, DateTimeOffset now) => _activeOrder.Where(code =>
    {
        if (!_stored.TryGetValue(code, out var stored))
        {
            return true;
        }
        if (stored.ReadOnly)
        {
            return false;
        }
        var st = Evaluate(code);
        return mode == FetchMode.All ? st.Status == PostStatus.Tracking : Tracker.IsDue(st, now);
    }).ToList();

    /// <summary>Applies a fetch result to the post metadata and returns the snapshot to save.</summary>
    public Snapshot Record(string shortcode, FetchResult result, DateTimeOffset capturedAt)
    {
        var meta = _metas[shortcode];
        Snapshot snapshot;
        if (result.IsOk)
        {
            var d = result.Data!;
            // The post text is saved once, with the first snapshot that has it.
            var caption = meta.Caption == null ? d.Caption : null;
            meta.Caption ??= d.Caption;
            // The page's publish time wins over the shortcode estimate.
            if (meta.PublishedAtSource != PublishedSource.Page && d.TakenAt != null)
            {
                meta.PublishedAt = d.TakenAt;
                meta.PublishedAtSource = PublishedSource.Page;
            }
            if (meta.Username == null && d.Username != null)
            {
                meta.Username = d.Username;
            }
            meta.CountsHidden = d.CountsHidden;

            snapshot = new Snapshot(capturedAt, shortcode, meta.Username, Hours(meta, capturedAt), FetchStatus.Ok,
                d.Views, d.Likes, d.Replies, d.Reposts, d.Quotes, d.Shares, null, _tracker.Options.TrackHours, caption);
        }
        else
        {
            snapshot = new Snapshot(capturedAt, shortcode, meta.Username, Hours(meta, capturedAt), result.Status,
                null, null, null, null, null, null, Text.OneLine(result.Error ?? result.Status), _tracker.Options.TrackHours);
        }

        if (snapshot.HoursSincePublish < 0)
        {
            // The computer clock is before the publish time: never write a negative age.
            snapshot = snapshot with
            {
                HoursSincePublish = 0,
                Error = snapshot.Error == null ? ClockBeforePublish : snapshot.Error + " (" + ClockBeforePublish + ")",
            };
        }

        _snapshots[shortcode].Add(snapshot);
        return snapshot;
    }

    /// <summary>One index entry per stored post, newest publish time first.</summary>
    public IReadOnlyList<IndexEntry> BuildIndex(IReadOnlyList<StoredPost> posts)
    {
        var list = new List<IndexEntry>();
        foreach (var p in posts)
        {
            var state = _tracker.Evaluate(p.Meta, p.Snapshots, _now);
            var active = _active.ContainsKey(p.Shortcode);
            string? note = null;
            if (state.Status == PostStatus.Tracking)
            {
                note = p.Archived ? Text.T.ArchivedNote
                    : p.ReadOnly && p.Problem != null ? null
                    : p.ReadOnly ? Text.T.DuplicateNote
                    : !active ? Text.T.RemovedFromUrls
                    : null;
            }
            list.Add(new IndexEntry(p, state, note)
            {
                Tags = active ? Tags(p.Shortcode) : [],
                UserNote = active ? _notes.GetValueOrDefault(p.Shortcode) : null,
                InputGroup = active ? _active[p.Shortcode].Group : null,
            });
        }

        return list
            .OrderByDescending(e => e.Post.Meta.PublishedAt.HasValue)
            .ThenByDescending(e => e.Post.Meta.PublishedAt)
            .ToList();
    }

    public const string ClockBeforePublish = "clock_before_publish";

    private static double? Hours(PostMeta meta, DateTimeOffset at) =>
        meta.PublishedAt is { } p ? Math.Round((at - p).TotalHours, 2, MidpointRounding.AwayFromZero) : null;
}

/// <summary>status/search filters: every --tag must be on the post (AND), and --group must match.</summary>
public sealed class PostFilter
{
    private readonly List<string> _tags = [];
    private readonly string? _group;

    public PostFilter(IEnumerable<string> tags, string? group)
    {
        foreach (var raw in tags)
        {
            if (Labels.NormalizeTag(raw) is { } t)
            {
                _tags.Add(t);
            }
            else
            {
                InvalidTag ??= raw;
            }
        }
        _group = string.IsNullOrWhiteSpace(group) ? null : group.Trim().Normalize(NormalizationForm.FormC);
    }

    /// <summary>The first --tag value that is not a valid tag, or null.</summary>
    public string? InvalidTag { get; }

    public bool IsEmpty => _tags.Count == 0 && _group == null;

    public bool Matches(IndexEntry e) =>
        _tags.All(t => e.Tags.Contains(t, Labels.TagComparer))
        && (_group == null || SameGroup(e.Post.Group) || SameGroup(e.InputGroup));

    private bool SameGroup(string? g) =>
        g != null && string.Equals(g.Normalize(NormalizationForm.FormC), _group, StringComparison.OrdinalIgnoreCase);

    /// <summary>For the title line: "#a #b, group X".</summary>
    public string Describe() =>
        string.Join(", ", new[]
        {
            _tags.Count > 0 ? string.Join(' ', _tags.Select(t => "#" + t)) : null,
            _group != null ? Text.T.GroupWord + " " + _group : null,
        }.Where(x => x != null));
}
