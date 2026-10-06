using System.Globalization;
using System.Text;
using ThreadsMonitor.Core;

namespace ThreadsMonitor.Store;

/// <summary>
/// Folder layout: one folder per post, inside a group folder chosen by the user.
///
///   work folder/
///     urls.txt, index.csv, log.txt, settings.ini, .lock
///     {group}/{yyyy-MM-dd}_{HH}h_@{username}_{shortcode}/snapshots.csv + info.txt
///
///  - snapshots.csv is the only real data. Every row carries the post's metadata, so the index and
///    info.txt can always be rebuilt from it. Identity is the shortcode column (case-sensitive),
///    never the folder name or location: users may rename folders or move them anywhere
///    (up to 6 levels deep, even inside another post folder, which is logged). Hidden folders are skipped.
///  - Columns are matched by header name and the separator is detected (Excel may save with ';').
///    New rows follow the file's own header: missing columns are skipped, extra ones left empty.
///  - A folder whose snapshots.csv has no readable shortcode is read-only; nothing is created or
///    fetched for the shortcode in its name until it is fixed.
///  - Rows never contain the raw urls.txt line, only its input_key (see InputKey). Tags and notes are
///    never stored here either: their only source is urls.txt.
///  - caption (the post text) is written once, in the first row that had it; readers take the first
///    non-empty value. Files without a caption column stay as they are (rows follow their header).
///  - Any folder on the path named "封存" or "archive" (any case) makes its posts read-only.
///  - The program never moves or deletes post folders. The only exception: a folder whose name starts
///    with 0000-00-00_ (publish time unknown when it was created) is renamed once when the time
///    becomes known, and only while it still sits directly in a group folder.
///  - UTF-8 with BOM, RFC 4180, null = empty cell (never 0), local time with offset.
///  - A locked snapshots.csv (open in Excel) is retried 3 times; then the row goes to
///    snapshots.pending-yyyyMMddHHmmss.csv in the same folder and is merged back on a later run:
///    renamed to .csv.merging, validated, rows not already in snapshots.csv appended, then deleted.
///    An interrupted merge resumes from the .merging file; an invalid file becomes .bad.csv.
///  - Group and post folders never start with a dot; a group named like the archive folder gets a
///    leading _. The 240 character path limit applies on Windows only.
///  - index.csv is written to .tmp and moved into place; when locked it is skipped (best effort).
/// </summary>
public sealed class FolderStore : IDataStore
{
    public static readonly string[] SnapshotColumns =
    [
        "captured_at_local", "captured_date", "captured_hour", "shortcode", "url", "username", "input_key",
        "published_at_local", "published_at_source", "counts_hidden", "hours_since_publish",
        "status", "views", "likes", "replies", "reposts", "quotes", "shares", "error", "track_hours", "caption",
    ];

    /// <summary>The columns written before the caption column was added (still read and appended to by name).</summary>
    public static readonly string[] PreviousSnapshotColumns = SnapshotColumns[..^1];

    /// <summary>The columns written by the first release (raw input_url, no track_hours).</summary>
    public static readonly string[] LegacySnapshotColumns =
    [
        "captured_at_local", "captured_date", "captured_hour", "shortcode", "url", "username", "input_url",
        "published_at_local", "published_at_source", "counts_hidden", "hours_since_publish",
        "status", "views", "likes", "replies", "reposts", "quotes", "shares", "error",
    ];

    public static readonly string[] IndexColumns =
    [
        "shortcode", "username", "url", "published_date", "published_hour", "published_at_local",
        "published_at_source", "status", "hours_since_publish", "views", "likes", "replies", "reposts", "quotes",
        "shares", "final_views", "final_likes", "final_replies", "final_reposts", "final_quotes", "final_shares",
        "final_captured_at_local", "late_final", "added_after_window", "counts_hidden", "last_error", "next_due_local",
        "group", "folder", "archived", "tags", "note", "caption",
    ];

    public const string TimeFormat = "yyyy-MM-dd'T'HH:mm:sszzz";
    public const string DataFile = "snapshots.csv";
    public const string InfoFile = "info.txt";
    public const string IndexFile = "index.csv";
    public const int MaxScanDepth = 6;
    public const string UngroupedZh = "未分組";
    public const string UngroupedEn = "Ungrouped";
    public const string PendingPrefix = "snapshots.pending-";
    public const string MergingSuffix = ".merging";
    public const string BadSuffix = ".bad.csv";
    private const string LongestFileName = "snapshots.pending-yyyyMMddHHmmss-NN.csv.merging";
    private const int WriteAttempts = 3;

    private readonly string _root;
    private readonly IFileOps _files;
    private readonly TimeProvider _time;
    private readonly Action<TimeSpan> _sleep;
    private readonly Action<string> _log;
    private readonly string _ungroupedName;
    private readonly bool _windowsPaths;
    private readonly int _maxPathLength;
    private readonly HashSet<string> _hostNames = new(StringComparer.OrdinalIgnoreCase);

    private readonly Dictionary<string, string> _writable = new(StringComparer.Ordinal);
    private readonly HashSet<string> _readOnly = new(StringComparer.Ordinal);
    private readonly Dictionary<string, string> _pendingByFolder = new(StringComparer.Ordinal);
    private readonly HashSet<string> _touched = new(StringComparer.Ordinal);
    private readonly List<string> _pendingWrites = [];
    private readonly List<string> _warnings = [];
    private readonly List<(string Name, string Location)> _unreadable = [];
    private readonly Dictionary<string, string> _blocked = new(StringComparer.Ordinal);
    private readonly HashSet<string> _warnedOnce = new(StringComparer.Ordinal);
    private bool _loaded;

    public FolderStore(string root, IFileOps files, TimeProvider time, Action<TimeSpan> sleep, Action<string> log, string ungroupedName,
        bool? windowsPaths = null, IEnumerable<string>? hostNames = null, int maxPathLength = Naming.MaxPathLength)
    {
        _maxPathLength = maxPathLength;
        _root = Path.GetFullPath(root);
        _files = files;
        _time = time;
        _sleep = sleep;
        _log = log;
        _ungroupedName = ungroupedName;
        _windowsPaths = windowsPaths ?? OperatingSystem.IsWindows();
        foreach (var h in hostNames ?? [])
        {
            AddHostName(h);
        }
    }

    public string IndexPath => Path.Combine(_root, IndexFile);

    public IReadOnlyList<string> PendingWrites => _pendingWrites;

    public IReadOnlyList<string> Warnings => _warnings;

    /// <summary>A computer name that sync programs may append to conflict copies ("folder-NAME").</summary>
    public void AddHostName(string? name)
    {
        if (!string.IsNullOrWhiteSpace(name))
        {
            _hostNames.Add(name.Trim());
        }
    }

    /// <summary>Absolute folder of a post that can be written, or null.</summary>
    public string? FolderOf(string shortcode) => _writable.GetValueOrDefault(shortcode);

    // ---------- time formatting ----------

    private DateTimeOffset ToLocal(DateTimeOffset t) => TimeZoneInfo.ConvertTime(t, _time.LocalTimeZone);

    public string FormatTime(DateTimeOffset t) => ToLocal(t).ToString(TimeFormat, CultureInfo.InvariantCulture);

    private string FormatDate(DateTimeOffset t) => ToLocal(t).ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);

    private string FormatHour(DateTimeOffset t) => ToLocal(t).Hour.ToString(CultureInfo.InvariantCulture);

    public static DateTimeOffset? ParseTime(string? s) =>
        DateTimeOffset.TryParse(s, CultureInfo.InvariantCulture, DateTimeStyles.AllowWhiteSpaces, out var t) ? t : null;

    // ---------- file names ----------

    private static bool IsPendingName(string name) =>
        name.StartsWith(PendingPrefix, StringComparison.Ordinal) && name.EndsWith(".csv", StringComparison.Ordinal)
        && !name.EndsWith(BadSuffix, StringComparison.Ordinal);

    private static bool IsMergingName(string name) =>
        name.StartsWith(PendingPrefix, StringComparison.Ordinal) && name.EndsWith(".csv" + MergingSuffix, StringComparison.Ordinal);

    /// <summary>Pending files of a folder: interrupted merges (.merging) first, then waiting ones, oldest first.</summary>
    private List<string> PendingFiles(string folder)
    {
        var all = _files.GetFiles(folder, PendingPrefix + "*").ToList();
        return all.Where(f => IsMergingName(Path.GetFileName(f))).OrderBy(f => f, StringComparer.Ordinal)
            .Concat(all.Where(f => IsPendingName(Path.GetFileName(f))).OrderBy(f => f, StringComparer.Ordinal))
            .ToList();
    }

    private static string RowKey(string code, DateTimeOffset at, string status) =>
        code + "|" + at.UtcTicks.ToString(CultureInfo.InvariantCulture) + "|" + status;

    // ---------- scan / load ----------

    private sealed record Found(string Folder, string Shortcode, PostMeta Meta, List<Snapshot> Snapshots, List<string> InputKeys,
        string? Group, string Location, bool Archived, string? Problem, bool Unreadable);

    public IReadOnlyList<StoredPost> LoadPosts()
    {
        _warnings.Clear();
        _unreadable.Clear();
        var found = new List<Found>();
        foreach (var folder in ScanPostFolders())
        {
            if (ReadFolder(folder) is { } f)
            {
                found.Add(f);
            }
        }

        _writable.Clear();
        _readOnly.Clear();
        var posts = new List<StoredPost>();
        foreach (var same in found.Where(f => !f.Unreadable).GroupBy(f => f.Shortcode, StringComparer.Ordinal))
        {
            var all = same.ToList();
            var active = all.Where(f => !f.Archived).ToList();
            Found chosen;
            bool readOnly;
            var snapshots = (IReadOnlyList<Snapshot>?)null;
            var inputs = (IReadOnlyList<string>?)null;
            string? problem = null;
            if (active.Count == 1)
            {
                chosen = active[0];
                readOnly = false;
            }
            else if (active.Count == 0)
            {
                chosen = all[0];
                readOnly = true;
            }
            else if (active.Where(f => !Naming.IsConflictName(Path.GetFileName(f.Folder), _hostNames)).ToList() is [var original])
            {
                // A sync program (OneDrive, Dropbox) made a conflict copy of the folder: keep tracking in
                // the original, count the rows of every copy, and ask the user to merge them.
                chosen = original;
                readOnly = false;
                var copies = active.Where(f => f != original).Select(f => f.Location).ToList();
                problem = Text.T.ConflictCopies(string.Join(", ", copies));
                _warnings.Add(problem);
                _log($"{same.Key}: conflict copies {string.Join(", ", copies)} of {original.Location}; tracking continues in {original.Location}");
                snapshots = Union(active.SelectMany(f => f.Snapshots));
                inputs = active.SelectMany(f => f.InputKeys).Distinct(StringComparer.Ordinal).ToList();
            }
            else
            {
                // Copied folder: do not guess which one is right.
                chosen = active[0];
                readOnly = true;
                _log($"{same.Key}: found in {active.Count} folders ({string.Join(", ", active.Select(a => a.Location))}); "
                     + "not fetched until only one is left outside the archive");
            }
            if (all.Count > 1 && active.Count <= 1)
            {
                _log($"{same.Key}: also found in {string.Join(", ", all.Where(f => f != chosen).Select(f => f.Location))}; using {chosen.Location}");
            }

            if (readOnly)
            {
                _readOnly.Add(chosen.Shortcode);
            }
            else
            {
                _writable[chosen.Shortcode] = chosen.Folder;
            }

            posts.Add(new StoredPost(chosen.Shortcode, chosen.Meta, snapshots ?? chosen.Snapshots, inputs ?? chosen.InputKeys, chosen.Group,
                chosen.Location, readOnly, chosen.Archived, problem ?? chosen.Problem));
        }

        foreach (var u in found.Where(f => f.Unreadable))
        {
            _unreadable.Add((Path.GetFileName(u.Folder), u.Location));
            _warnings.Add(Text.T.FolderUnreadable(u.Location));
            _log($"{u.Location}: snapshots.csv has no readable shortcode; the folder is read-only until it is fixed");
            if (u.Shortcode.Length > 0 && !posts.Any(p => p.Shortcode == u.Shortcode))
            {
                _readOnly.Add(u.Shortcode);
                posts.Add(new StoredPost(u.Shortcode, u.Meta, [], [], u.Group, u.Location, true, u.Archived, Text.T.UnreadableNote));
            }
        }

        foreach (var file in _files.GetFiles(_root, "*"))
        {
            var name = Path.GetFileName(file);
            if ((name.StartsWith("urls", StringComparison.OrdinalIgnoreCase) || name.StartsWith("settings", StringComparison.OrdinalIgnoreCase)
                 || name.StartsWith("index", StringComparison.OrdinalIgnoreCase))
                && Naming.IsConflictName(name, _hostNames))
            {
                _warnings.Add(Text.T.ConflictCopies(name));
            }
        }

        _loaded = true;
        return posts;
    }

    private static List<Snapshot> Union(IEnumerable<Snapshot> snapshots)
    {
        var seen = new HashSet<string>(StringComparer.Ordinal);
        return snapshots.Where(s => seen.Add(RowKey(s.Shortcode, s.CapturedAt, s.Status))).OrderBy(s => s.CapturedAt).ToList();
    }

    private bool IsPostFolder(string dir) => _files.Exists(Path.Combine(dir, DataFile)) || PendingFiles(dir).Count > 0;

    private List<string> ScanPostFolders()
    {
        var result = new List<string>();
        var queue = new Queue<(string Dir, int Depth, bool InPost)>();
        queue.Enqueue((_root, 0, false));
        while (queue.Count > 0)
        {
            var (dir, depth, inPost) = queue.Dequeue();
            var isPost = depth > 0 && IsPostFolder(dir);
            if (isPost)
            {
                result.Add(dir);
                if (inPost)
                {
                    _log($"{Relative(dir)}: a post folder inside another post folder; it still works, but please move it out");
                }
            }
            if (depth >= MaxScanDepth)
            {
                continue;
            }
            foreach (var sub in _files.GetDirectories(dir).OrderBy(d => d, StringComparer.Ordinal))
            {
                queue.Enqueue((sub, depth + 1, inPost || isPost));
            }
        }

        return result;
    }

    private Found? ReadFolder(string folder)
    {
        var sources = new List<string>();
        var main = Path.Combine(folder, DataFile);
        if (_files.Exists(main))
        {
            sources.Add(main);
        }
        sources.AddRange(PendingFiles(folder));

        string? code = null;
        string? username = null, published = null, source = null, hidden = null, caption = null;
        var snapshots = new List<Snapshot>();
        var seen = new HashSet<string>(StringComparer.Ordinal);
        var inputs = new List<string>();
        var location = Relative(folder);

        foreach (var file in sources)
        {
            List<Dictionary<string, string>> rows;
            try
            {
                rows = Csv.Read(_files.ReadAllText(file)).Rows;
            }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException)
            {
                _log($"{Relative(file)}: cannot read ({e.Message})");
                continue;
            }

            foreach (var r in rows)
            {
                var rowCode = Cell(r, "shortcode");
                if (code == null && Shortcode.IsValid(rowCode))
                {
                    code = rowCode;
                }
                var at = ParseTime(Get(r, "captured_at_local"));
                var status = Get(r, "status");
                if (rowCode != code || at == null || status.Length == 0)
                {
                    _log($"{Relative(file)}: skipped a row that does not belong to {code ?? "?"} or cannot be read");
                    continue;
                }
                if (!seen.Add(RowKey(rowCode, at.Value, status)))
                {
                    continue; // the same row in a pending file and in snapshots.csv
                }

                username = LastNonEmpty(username, Cell(r, "username"));
                published = LastNonEmpty(published, Get(r, "published_at_local"));
                source = LastNonEmpty(source, Get(r, "published_at_source"));
                hidden = LastNonEmpty(hidden, Get(r, "counts_hidden"));
                var rowCaption = Labels.UnsafeCell(RawGet(r, "caption"));
                caption ??= rowCaption is { Length: > 0 } && !string.IsNullOrWhiteSpace(rowCaption) ? rowCaption : null;
                var key = Get(r, "input_key");
                if (key.Length == 0 && Get(r, "input_url") is { Length: > 0 } legacy)
                {
                    key = InputKey.Of(legacy) ?? ""; // old format: compared in memory only, never written back
                }
                if (key.Length > 0)
                {
                    inputs.Remove(key);
                    inputs.Add(key); // most recent last
                }

                snapshots.Add(new Snapshot(
                    at.Value, rowCode, NullIfEmpty(Cell(r, "username")),
                    double.TryParse(Get(r, "hours_since_publish"), NumberStyles.Float, CultureInfo.InvariantCulture, out var h) ? h : null,
                    status,
                    Long(Get(r, "views")), Long(Get(r, "likes")), Long(Get(r, "replies")),
                    Long(Get(r, "reposts")), Long(Get(r, "quotes")), Long(Get(r, "shares")),
                    NullIfEmpty(Cell(r, "error")),
                    int.TryParse(Get(r, "track_hours"), NumberStyles.None, CultureInfo.InvariantCulture, out var th) && th > 0 ? th : null,
                    NullIfEmpty(Labels.UnsafeCell(RawGet(r, "caption")) ?? "")));
            }
        }

        var segments = location.Split('/', StringSplitOptions.RemoveEmptyEntries);
        var archived = segments.Any(Naming.IsArchiveName);
        var group = segments.Length >= 2 ? segments[0] : null;

        if (code == null)
        {
            if (sources.Count == 0)
            {
                return null;
            }
            var guess = GuessShortcode(Path.GetFileName(folder)) ?? "";
            return new Found(folder, guess, new PostMeta { Shortcode = guess }, [], [], group, location, archived, Text.T.UnreadableNote, true);
        }

        var meta = new PostMeta
        {
            Shortcode = code,
            Username = username,
            PublishedAt = ParseTime(published),
            PublishedAtSource = source is PublishedSource.Page or PublishedSource.Shortcode ? source : null,
            CountsHidden = string.Equals(hidden, "true", StringComparison.OrdinalIgnoreCase),
            Caption = caption,
        };

        string? problem = null;
        var copies = _files.GetFiles(folder, "*.csv")
            .Select(Path.GetFileName)
            .Where(n => n != null && n != DataFile && !n.StartsWith(PendingPrefix, StringComparison.Ordinal)
                        && Naming.IsConflictName(n, _hostNames))
            .Select(n => location + "/" + n)
            .OrderBy(n => n, StringComparer.Ordinal)
            .ToList();
        if (copies.Count > 0)
        {
            problem = Text.T.ConflictCopies(string.Join(", ", copies));
            _warnings.Add(problem);
        }

        return new Found(folder, code, meta, snapshots.OrderBy(s => s.CapturedAt).ToList(), inputs, group, location, archived, problem, false);
    }

    /// <summary>The shortcode in a post folder name made by this program ("..._@user_CODE", "..._CODE_2"), for display only.</summary>
    private static string? GuessShortcode(string folderName)
    {
        var name = System.Text.RegularExpressions.Regex.Replace(folderName, @"_[0-9]{1,3}$", "");
        var at = name.LastIndexOf('@');
        if (at >= 0)
        {
            var underscore = name.IndexOf('_', at);
            if (underscore < 0)
            {
                return null;
            }
            // Usernames may contain _, so prefer the shortest valid tail.
            name = name[(underscore + 1)..];
        }
        string? best = null;
        for (var i = name.Length - 1; i >= 0; i--)
        {
            if (name[i] == '_' && Shortcode.IsValid(name[(i + 1)..]))
            {
                best = name[(i + 1)..];
                break;
            }
        }

        return best ?? (Shortcode.IsValid(name) ? name : null);
    }

    /// <summary>True when an unreadable folder's name is the one this program would give the shortcode.</summary>
    private static bool NameMentions(string folderName, string code) =>
        folderName == code
        || folderName.EndsWith("_" + code, StringComparison.Ordinal)
        || System.Text.RegularExpressions.Regex.IsMatch(folderName, "_" + System.Text.RegularExpressions.Regex.Escape(code) + @"_[0-9]{1,3}$");

    // ---------- pending ----------

    public int MergePending()
    {
        if (!_loaded)
        {
            LoadPosts();
        }

        var merged = 0;
        foreach (var (code, folder) in _writable.ToList())
        {
            List<string> files;
            try
            {
                files = PendingFiles(folder);
            }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException)
            {
                _log($"{Relative(folder)}: cannot list pending files ({e.Message})");
                continue;
            }

            foreach (var file in files)
            {
                try
                {
                    var result = MergeOne(code, folder, file);
                    if (result == MergeResult.Merged)
                    {
                        merged++;
                    }
                    else if (result == MergeResult.Locked)
                    {
                        break; // snapshots.csv still locked: keep the rest for next time
                    }
                }
                catch (Exception e) when (e is IOException or UnauthorizedAccessException)
                {
                    _log($"{Relative(file)}: could not merge ({e.Message}); it is kept and retried next time");
                }
            }
        }

        return merged;
    }

    private enum MergeResult
    {
        Merged,
        Bad,
        Locked,
    }

    private MergeResult MergeOne(string code, string folder, string file)
    {
        // 1. Claim the file. A .merging file is a merge that was interrupted: just continue it.
        var merging = file;
        if (!IsMergingName(Path.GetFileName(file)))
        {
            merging = file + MergingSuffix;
            _files.Move(file, merging, overwrite: false);
        }

        // 2. Validate before touching snapshots.csv.
        var table = Csv.Read(_files.ReadAllText(merging));
        var reason = Validate(table, code);
        if (reason != null)
        {
            var name = Path.GetFileName(merging);
            var stem = name[..^(".csv" + MergingSuffix).Length];
            var bad = Path.Combine(folder, stem + BadSuffix);
            for (var n = 2; _files.Exists(bad); n++)
            {
                bad = Path.Combine(folder, stem + "-" + n.ToString(CultureInfo.InvariantCulture) + BadSuffix);
            }
            _files.Move(merging, bad, overwrite: false);
            _log($"{Relative(file)}: not merged ({reason}); renamed to {Path.GetFileName(bad)}");
            return MergeResult.Bad;
        }

        // 3. Append only rows that snapshots.csv does not have yet (idempotent).
        var main = Path.Combine(folder, DataFile);
        var existing = new HashSet<string>(StringComparer.Ordinal);
        if (_files.Exists(main))
        {
            foreach (var r in Csv.Read(_files.ReadAllText(main)).Rows)
            {
                if (ParseTime(Get(r, "captured_at_local")) is { } at)
                {
                    existing.Add(RowKey(Cell(r, "shortcode"), at, Get(r, "status")));
                }
            }
        }
        var rows = new List<Dictionary<string, string>>();
        foreach (var r in table.Rows)
        {
            if (!existing.Add(RowKey(Cell(r, "shortcode"), ParseTime(Get(r, "captured_at_local"))!.Value, Get(r, "status"))))
            {
                continue;
            }
            if (!r.ContainsKey("input_key") && r.Remove("input_url", out var legacy))
            {
                r["input_key"] = InputKey.Of(legacy) ?? "";
            }
            rows.Add(r);
        }

        if (rows.Count > 0 && !TryWrite(() => AppendRows(folder, rows)))
        {
            _log($"{Relative(file)}: snapshots.csv is still locked; merge continues next time");
            return MergeResult.Locked;
        }

        // 4. Done. If the delete fails, the next run finds the .merging file and adds nothing twice.
        _files.Delete(merging);
        _log($"{Relative(file)}: merged {rows.Count} row(s) into snapshots.csv");
        return MergeResult.Merged;
    }

    private static string? Validate(Csv.Table table, string code)
    {
        if (!table.Header.SequenceEqual(SnapshotColumns, StringComparer.Ordinal)
            && !table.Header.SequenceEqual(PreviousSnapshotColumns, StringComparer.Ordinal)
            && !table.Header.SequenceEqual(LegacySnapshotColumns, StringComparer.Ordinal))
        {
            return "unexpected header";
        }
        for (var i = 0; i < table.Rows.Count; i++)
        {
            var r = table.Rows[i];
            if (table.FieldCounts[i] != table.Header.Count)
            {
                return $"row {i + 2} has {table.FieldCounts[i]} fields";
            }
            if (Cell(r, "shortcode") != code || !Shortcode.IsValid(code))
            {
                return $"row {i + 2} has another shortcode";
            }
            if (ParseTime(Get(r, "captured_at_local")) == null || Get(r, "status").Length == 0)
            {
                return $"row {i + 2} has no valid captured_at_local/status";
            }
        }

        return null;
    }

    /// <summary>
    /// Appends rows to a post's snapshots.csv, following the file's own header and separator (it may have
    /// been edited or re-saved in Excel), and ending the last line first when it has no line break.
    /// </summary>
    private void AppendRows(string folder, IReadOnlyList<Dictionary<string, string>> rows)
    {
        var path = Path.Combine(folder, DataFile);
        string text = _files.Exists(path) ? _files.ReadAllText(path) : "";
        var table = Csv.Read(text);
        if (table.Header.Count == 0 || table.Header.All(h => h.Length == 0))
        {
            // New (or empty) file.
            _files.WriteAllText(path, Csv.Line(SnapshotColumns) + string.Concat(rows.Select(r => Csv.Line(SnapshotColumns.Select(r.GetValueOrDefault)))), withBom: true);
            return;
        }

        var same = table.Delimiter == ',' && table.Header.SequenceEqual(SnapshotColumns, StringComparer.Ordinal);
        var previous = table.Delimiter == ',' && table.Header.SequenceEqual(PreviousSnapshotColumns, StringComparer.Ordinal);
        if (!same && !previous && _warnedOnce.Add("header:" + folder))
        {
            _log($"{Relative(path)}: its columns differ from the expected ones; new rows are written by column name "
                 + "(columns it does not have are skipped, extra ones are left empty)");
        }

        var sb = new StringBuilder();
        if (!text.EndsWith('\n'))
        {
            sb.Append("\r\n");
        }
        IEnumerable<string> columns = same ? SnapshotColumns : table.Header;
        foreach (var r in rows)
        {
            sb.Append(Csv.Line(columns.Select(c => r.GetValueOrDefault(c)), table.Delimiter));
        }
        _files.AppendAllText(path, sb.ToString());
    }

    // ---------- append ----------

    public Dictionary<string, string> SnapshotRow(Snapshot s, PostMeta meta, string? inputKey)
    {
        var values = new string?[]
        {
            FormatTime(s.CapturedAt), FormatDate(s.CapturedAt), FormatHour(s.CapturedAt),
            // Text that comes from the user (shortcode, from the URL) or from Threads (username, error,
            // caption) is protected against Excel formulas. url always starts with "https://" and
            // input_key is a hash, so they need no protection.
            Labels.SafeCell(s.Shortcode), ThreadsUrl.Clean(meta.Shortcode, meta.Username), Labels.SafeCell(meta.Username), inputKey,
            meta.PublishedAt is { } p ? FormatTime(p) : null, meta.PublishedAtSource, Bool(meta.CountsHidden),
            s.HoursSincePublish?.ToString("0.00", CultureInfo.InvariantCulture), s.Status,
            Num(s.Views), Num(s.Likes), Num(s.Replies), Num(s.Reposts), Num(s.Quotes), Num(s.Shares), Labels.SafeCell(s.Error),
            s.TrackHours?.ToString(CultureInfo.InvariantCulture), Labels.SafeCell(s.Caption),
        };
        var row = new Dictionary<string, string>(StringComparer.Ordinal);
        for (var i = 0; i < SnapshotColumns.Length; i++)
        {
            row[SnapshotColumns[i]] = values[i] ?? "";
        }
        return row;
    }

    public string SnapshotLine(Snapshot s, PostMeta meta, string? inputKey)
    {
        var row = SnapshotRow(s, meta, inputKey);
        return Csv.Line(SnapshotColumns.Select(c => row[c]));
    }

    public string? CannotStore(string shortcode, PostMeta meta, string? group)
    {
        if (!_loaded)
        {
            LoadPosts();
        }
        if (_writable.ContainsKey(shortcode) || _readOnly.Contains(shortcode))
        {
            return null;
        }
        if (_blocked.TryGetValue(shortcode, out var known))
        {
            return known;
        }

        string? reason = null;
        var unreadable = _unreadable.FirstOrDefault(u => NameMentions(u.Name, shortcode));
        if (unreadable.Location != null)
        {
            reason = Text.T.FolderUnreadable(unreadable.Location);
        }
        else if (_windowsPaths)
        {
            try
            {
                NewPostPath(Path.Combine(_root, GroupName(group)), meta, except: null);
            }
            catch (IOException e)
            {
                reason = e.Message;
            }
        }

        if (reason != null)
        {
            _blocked[shortcode] = reason;
            _log($"{shortcode}: not fetched: {reason}");
        }
        return reason;
    }

    public void AppendSnapshot(Snapshot snapshot, PostMeta meta, string? inputKey, string? group)
    {
        if (!_loaded)
        {
            LoadPosts();
        }
        var code = snapshot.Shortcode;
        if (_readOnly.Contains(code))
        {
            throw new InvalidOperationException($"{code} is read-only (archived, duplicated or unreadable).");
        }
        if (_blocked.TryGetValue(code, out var blocked))
        {
            throw new IOException(blocked);
        }

        var folder = _writable.TryGetValue(code, out var existing) ? existing : CreatePostFolder(meta, group);
        var row = SnapshotRow(snapshot, meta, inputKey);
        var line = Csv.Line(SnapshotColumns.Select(c => row[c]));

        if (!_pendingByFolder.TryGetValue(folder, out var pending) && TryWrite(() => AppendRows(folder, [row])))
        {
            _touched.Add(code);
            RenameIfDateUnknown(code, folder, meta);
            return;
        }

        if (pending == null)
        {
            var stamp = ToLocal(_time.GetUtcNow()).ToString("yyyyMMddHHmmss", CultureInfo.InvariantCulture);
            pending = Path.Combine(folder, $"{PendingPrefix}{stamp}.csv");
            for (var n = 2; _files.Exists(pending) || _files.Exists(pending + MergingSuffix); n++)
            {
                pending = Path.Combine(folder, $"{PendingPrefix}{stamp}-{n:00}.csv");
            }
            _files.WriteAllText(pending, Csv.Line(SnapshotColumns) + line, withBom: true);
            _pendingByFolder[folder] = pending;
            _pendingWrites.Add(Relative(pending));
        }
        else
        {
            _files.AppendAllText(pending, line);
        }
        _touched.Add(code);
    }

    /// <summary>Folder name for a group (not created). Archive names get a leading _ so new posts are not frozen.</summary>
    private string GroupName(string? group)
    {
        if (string.IsNullOrWhiteSpace(group))
        {
            // The default group keeps whatever language it was first created in.
            return FindChild(_root, UngroupedZh) ?? FindChild(_root, UngroupedEn) ?? _ungroupedName;
        }

        var clean = Naming.Sanitize(group, Naming.MaxGroupLength);
        if (Naming.IsArchiveName(clean))
        {
            var renamed = "_" + clean;
            if (_warnedOnce.Add("group:" + clean))
            {
                _log($"warning: group \"{clean}\" has the name of the archive folder; its posts go to \"{renamed}\" instead");
            }
            clean = renamed;
        }
        return FindChild(_root, clean) ?? clean;
    }

    private string GroupFolder(string? group)
    {
        var path = Path.Combine(_root, GroupName(group));
        _files.CreateDirectory(path);
        return path;
    }

    /// <summary>Existing child folder name matching case-insensitively (after NFC), or null.</summary>
    private string? FindChild(string dir, string name)
    {
        var want = name.Normalize(NormalizationForm.FormC);
        return _files.GetDirectories(dir)
            .Select(Path.GetFileName)
            .FirstOrDefault(n => n != null && n.Normalize(NormalizationForm.FormC).Equals(want, StringComparison.OrdinalIgnoreCase));
    }

    private bool TooLong(string folder) =>
        _windowsPaths && Path.GetFullPath(folder).Length + 1 + LongestFileName.Length > _maxPathLength;

    private string CreatePostFolder(PostMeta meta, string? group)
    {
        var groupDir = GroupFolder(group);
        var path = NewPostPath(groupDir, meta, except: null);
        _files.CreateDirectory(path);
        _writable[meta.Shortcode] = path;
        _log($"{meta.Shortcode}: created folder {Relative(path)}");
        return path;
    }

    private string NewPostPath(string groupDir, PostMeta meta, string? except)
    {
        DateTimeOffset? local = meta.PublishedAt is { } p ? ToLocal(p) : null;
        var siblings = _files.GetDirectories(groupDir)
            .Where(d => except == null || !string.Equals(d, except, StringComparison.Ordinal))
            .Select(d => Path.GetFileName(d)!)
            .ToList();

        var path = Path.Combine(groupDir, Naming.Unique(Naming.PostFolder(local, meta.Username, meta.Shortcode), siblings));
        if (TooLong(path))
        {
            path = Path.Combine(groupDir, Naming.Unique(Naming.ShortPostFolder(local, meta.Shortcode), siblings));
        }
        if (TooLong(path))
        {
            throw new IOException(Text.T.PathTooLong(_root));
        }

        return path;
    }

    private string RenameIfDateUnknown(string code, string folder, PostMeta meta)
    {
        var name = Path.GetFileName(folder);
        if (meta.PublishedAt == null || !name.StartsWith(Naming.UnknownDatePrefix, StringComparison.Ordinal))
        {
            return folder;
        }
        var groupDir = Path.GetDirectoryName(folder)!;
        if (!string.Equals(Path.GetDirectoryName(groupDir), _root, StringComparison.Ordinal))
        {
            return folder; // the user moved it somewhere else: leave it alone
        }

        try
        {
            var target = NewPostPath(groupDir, meta, except: folder);
            _files.MoveDirectory(folder, target);
            _writable[code] = target;
            _log($"{code}: renamed {Relative(folder)} -> {Relative(target)}");
            return target;
        }
        catch (Exception e) when (e is IOException or UnauthorizedAccessException)
        {
            _log($"{code}: could not rename {Relative(folder)}: {e.Message}");
            return folder;
        }
    }

    // ---------- index / info ----------

    public bool WriteIndex(IReadOnlyList<IndexEntry> entries, DateTimeOffset now)
    {
        foreach (var e in entries)
        {
            if (FolderOf(e.Post.Shortcode) is { } folder && !e.Post.ReadOnly)
            {
                var info = Path.Combine(folder, InfoFile);
                var body = InfoBody(e, now);
                if (_touched.Contains(e.Post.Shortcode) || !_files.Exists(info) || InfoChanged(info, body))
                {
                    try
                    {
                        _files.WriteAllText(info, body + "\r\n" + Text.T.InfoFooter(FormatTime(now)) + "\r\n", withBom: true);
                    }
                    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
                    {
                        _log($"{e.Post.Shortcode}: could not write info.txt: {ex.Message}");
                    }
                }
            }
        }

        var sorted = entries
            .OrderByDescending(e => e.Post.Meta.PublishedAt.HasValue)
            .ThenByDescending(e => e.Post.Meta.PublishedAt)
            .ToList();
        var text = Csv.Line(IndexColumns) + string.Concat(sorted.Select(e => Csv.Line(IndexValues(e, now))));
        var tmp = IndexPath + ".tmp";
        try
        {
            _files.WriteAllText(tmp, text, withBom: true);
            if (TryWrite(() => _files.Move(tmp, IndexPath, overwrite: true)))
            {
                return true;
            }
            _files.Delete(tmp);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            _log("index.csv: " + ex.Message);
        }

        _log("index.csv is locked; it will be rebuilt next time");
        return false;
    }

    private string? DueText(IndexEntry e, DateTimeOffset now) =>
        e.Note == null && !e.Post.ReadOnly && e.State.NextDue is { } d ? FormatTime(d == DateTimeOffset.MinValue ? now : d) : null;

    private IEnumerable<string?> IndexValues(IndexEntry e, DateTimeOffset now)
    {
        var m = e.Post.Meta;
        var s = e.State;
        var last = s.LastOk;
        var fin = s.Final;

        // Every text that comes from the user (urls.txt, folder names) or from Threads goes through
        // SafeCell. url always starts with "https://"; the other columns are numbers, times, booleans
        // or fixed words written by this program.
        return
        [
            Labels.SafeCell(m.Shortcode),
            Labels.SafeCell(m.Username),
            ThreadsUrl.Clean(m.Shortcode, m.Username),
            m.PublishedAt is { } p1 ? FormatDate(p1) : null,
            m.PublishedAt is { } p2 ? FormatHour(p2) : null,
            m.PublishedAt is { } p3 ? FormatTime(p3) : null,
            m.PublishedAtSource,
            s.Status,
            last?.HoursSincePublish?.ToString("0.00", CultureInfo.InvariantCulture),
            Num(last?.Views), Num(last?.Likes), Num(last?.Replies), Num(last?.Reposts), Num(last?.Quotes), Num(last?.Shares),
            Num(fin?.Views), Num(fin?.Likes), Num(fin?.Replies), Num(fin?.Reposts), Num(fin?.Quotes), Num(fin?.Shares),
            fin != null ? FormatTime(fin.CapturedAt) : null,
            Bool(s.LateFinal),
            Bool(s.AddedAfterWindow),
            Bool(m.CountsHidden),
            Labels.SafeCell(e.Note ?? e.Post.Problem ?? s.LastError),
            DueText(e, now),
            Labels.SafeCell(e.Post.Group),
            Labels.SafeCell(e.Post.Location),
            Bool(e.Post.Archived),
            Labels.SafeCell(Labels.JoinTags(e.Tags)),
            Labels.SafeCell(e.UserNote),
            Labels.SafeCell(Labels.ShortCaption(m.Caption)),
        ];
    }

    /// <summary>
    /// True when info.txt says something else than <paramref name="body"/> (the footer, which only
    /// carries the time, is not compared). Tags and notes change in urls.txt without any fetch.
    /// </summary>
    private bool InfoChanged(string path, string body)
    {
        try
        {
            var text = _files.ReadAllText(path).TrimStart(Csv.Bom);
            return !text.StartsWith(body + "\r\n", StringComparison.Ordinal);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            return false;
        }
    }

    /// <summary>info.txt without its footer line.</summary>
    private string InfoBody(IndexEntry e, DateTimeOffset now)
    {
        var T = Text.T;
        var m = e.Post.Meta;
        var s = e.State;
        var sb = new StringBuilder();
        void Line(string label, string? value) => sb.Append(label).Append(": ").Append(value ?? "-").Append("\r\n");
        string Metrics(Snapshot? x) => x == null
            ? "-"
            : string.Join(" / ", new[]
            {
                (T.TableHeaders[4], x.Views), (T.TableHeaders[5], x.Likes), (T.TableHeaders[6], x.Replies),
                (T.TableHeaders[7], x.Reposts), (T.InfoQuotes, x.Quotes), (T.InfoShares, x.Shares),
            }.Select(t => $"{t.Item1} {(t.Item2.HasValue ? t.Item2.Value.ToString("N0", CultureInfo.InvariantCulture) : "-")}"));

        sb.Append(T.InfoTitle).Append("\r\n\r\n");
        Line("URL", ThreadsUrl.Clean(m.Shortcode, m.Username));
        Line(T.InfoAccount, m.Username != null ? "@" + m.Username : null);
        Line("shortcode", m.Shortcode);
        Line(T.InfoPublished, m.PublishedAt is { } p ? FormatTime(p) + $" ({m.PublishedAtSource})" : null);
        Line(T.TableHeaders[2], T.StatusName(s.Status));
        Line(T.InfoLatest, s.LastOk == null ? null
            : $"{FormatTime(s.LastOk.CapturedAt)} ({s.LastOk.HoursSincePublish?.ToString("0.0", CultureInfo.InvariantCulture)} h) {Metrics(s.LastOk)}");
        Line(T.InfoFinal, s.Final == null ? null
            : $"{FormatTime(s.Final.CapturedAt)} {Metrics(s.Final)}"
              + (s.LateFinal ? " [late_final]" : "") + (s.AddedAfterWindow ? " [added_after_window]" : ""));
        if (m.CountsHidden)
        {
            Line(T.InfoNote, T.InfoCountsHidden);
        }
        var errText = e.Note ?? e.Post.Problem ?? s.LastError;
        if (errText != null)
        {
            Line(T.InfoLastError, errText);
        }
        Line(T.TableHeaders[8], DueText(e, now));
        if (e.Tags.Count > 0)
        {
            Line(T.InfoTags, string.Join(" ", e.Tags.OrderBy(t => t, StringComparer.OrdinalIgnoreCase).Select(t => "#" + t)));
        }
        if (e.UserNote != null)
        {
            Line(T.InfoUserNote, e.UserNote);
        }
        if (m.Caption != null)
        {
            sb.Append("\r\n").Append(T.InfoCaption).Append(":\r\n");
            sb.Append(m.Caption.Replace("\r\n", "\n").Replace('\r', '\n').Replace("\n", "\r\n")).Append("\r\n");
        }
        return sb.ToString();
    }

    // ---------- helpers ----------

    private string Relative(string path) =>
        Path.GetRelativePath(_root, path).Replace(Path.DirectorySeparatorChar, '/');

    private bool TryWrite(Action write)
    {
        for (var attempt = 1; attempt <= WriteAttempts; attempt++)
        {
            try
            {
                write();
                return true;
            }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException)
            {
                if (attempt < WriteAttempts)
                {
                    _sleep(TimeSpan.FromSeconds(1));
                }
            }
        }

        return false;
    }

    private static string? LastNonEmpty(string? current, string value) => value.Length > 0 ? value : current;

    private static string Bool(bool b) => b ? "true" : "false";

    private static string? Num(long? v) => v?.ToString(CultureInfo.InvariantCulture);

    private static string Get(Dictionary<string, string> r, string key) => r.TryGetValue(key, out var v) ? v.Trim() : "";

    /// <summary>A text cell that was written through Labels.SafeCell, with the protecting apostrophe removed.</summary>
    private static string Cell(Dictionary<string, string> r, string key) => Labels.UnsafeCell(Get(r, key)) ?? "";

    /// <summary>A cell as written (not trimmed), or null when the column is missing.</summary>
    private static string? RawGet(Dictionary<string, string> r, string key) => r.GetValueOrDefault(key);

    private static string? NullIfEmpty(string s) => s.Length == 0 ? null : s;

    private static long? Long(string s) =>
        long.TryParse(s, NumberStyles.None, CultureInfo.InvariantCulture, out var n) ? n : null;
}
