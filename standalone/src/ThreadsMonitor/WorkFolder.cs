using System.Globalization;
using System.Text;
using ThreadsMonitor.Core;
using ThreadsMonitor.Store;

namespace ThreadsMonitor;

/// <summary>
/// One URL line of urls.txt and the group heading above it (null = no group). <see cref="Url"/> is the
/// URL alone; the line's tags (spelled as they first appear in the file) and note are kept apart.
/// </summary>
public sealed record UrlEntry(int Line, string Url, string? Group)
{
    public IReadOnlyList<string> Tags { get; init; } = [];
    public string? Note { get; init; }
}

public enum LockState
{
    Acquired,
    /// <summary>Another run on this computer holds the lock.</summary>
    Busy,
    /// <summary>Another computer used this folder less than 30 minutes ago (synced folder).</summary>
    OtherComputer,
    /// <summary>The .lock file cannot be created or written (read-only, no permission).</summary>
    Unwritable,
}

public sealed record LockResult(LockState State, IDisposable? Handle, string? OtherHost = null, DateTimeOffset? OtherTime = null,
    string? PreviousHost = null);

/// <summary>
/// The work folder's non-data files: urls.txt (input), settings.ini, log.txt and .lock.
/// Tracking data (post folders, index) lives behind IDataStore.
/// </summary>
public sealed class WorkFolder(string directory, IFileOps files, TimeProvider time, int? ansiCodePage = null)
{
    private const long LogMaxBytes = 1024 * 1024;
    public static readonly TimeSpan OtherComputerWindow = TimeSpan.FromMinutes(30);

    public string Directory { get; } = directory;
    public string UrlsPath => Path.Combine(Directory, "urls.txt");
    public string LogPath => Path.Combine(Directory, "log.txt");
    public string SettingsPath => Path.Combine(Directory, "settings.ini");
    public string LockPath => Path.Combine(Directory, ".lock");

    public Settings LoadSettings(Action<string>? warn = null) =>
        files.Exists(SettingsPath) ? Settings.Parse(files.ReadAllText(SettingsPath), warn) : new Settings();

    /// <summary>Creates urls.txt from the template when missing. Returns true when it was created.</summary>
    public bool EnsureUrlsFile(string template)
    {
        if (files.Exists(UrlsPath))
        {
            return false;
        }
        files.WriteAllText(UrlsPath, template.Replace("\r\n", "\n").Replace("\n", Environment.NewLine), withBom: false);
        return true;
    }

    /// <summary>
    /// URL lines of urls.txt with their 1-based line numbers and group. "## name" or "##name" (two #,
    /// not followed by a third) starts a group; any other line starting with # is a comment.
    /// Invisible format characters (pasted from chat apps) are removed first, and the full-width
    /// ＃ and ｜ of Chinese/Japanese input methods count as # and |. A file that is not UTF-8
    /// is read with the Windows ANSI code page (then Big5, GBK, Shift-JIS), with a warning.
    /// A comment line that looks like "#tag URL" gets a warning: the URL on it is not tracked.
    /// Warnings go to <paramref name="warn"/> (English, for log.txt) and <paramref name="show"/>
    /// (in the UI language, for the console).
    /// A URL line may carry tags and a note: "&lt;url&gt; #tag #tag | note" (see Labels). A tag written
    /// with different case on several lines keeps the spelling of its first appearance.
    /// </summary>
    public List<UrlEntry> ReadUrls(Action<string>? warn = null, Action<string>? show = null)
    {
        var result = new List<UrlEntry>();
        if (!files.Exists(UrlsPath))
        {
            return result;
        }

        string? group = null;
        var spelling = new Dictionary<string, string>(Labels.TagComparer);
        var text = Decode(files.ReadAllBytes(UrlsPath), ansiCodePage, out var fallback);
        if (fallback != null)
        {
            warn?.Invoke($"warning: urls.txt is not saved as UTF-8; read it as {fallback}. Save it as UTF-8 to be sure");
            show?.Invoke(Text.T.UrlsNotUtf8(fallback));
        }
        var lines = text.Split('\n');
        for (var i = 0; i < lines.Length; i++)
        {
            var line = Labels.StripInvisible(lines[i]).Replace('\uFF03', '#').Replace('\uFF5C', '|').Trim();
            var lineNo = i + 1;
            if (line.Length == 0)
            {
                continue;
            }
            if (line.StartsWith("##", StringComparison.Ordinal) && (line.Length == 2 || line[2] != '#'))
            {
                var name = line[2..].Trim(); // an empty heading ends the current group
                group = name.Length == 0 ? null : name;
                continue;
            }
            if (line.StartsWith('#'))
            {
                if (LooksLikeTagBeforeUrl(line))
                {
                    warn?.Invoke($"urls.txt line {lineNo}: this line is a comment, so it is not tracked; write tags after the URL");
                    show?.Invoke(Text.T.UrlsLine(lineNo, Text.T.UrlsCommentedUrl));
                }
                continue;
            }
            var parsed = Labels.ParseLine(line,
                warn == null ? null : m => warn($"urls.txt line {lineNo}: {m}"),
                show == null ? null : m => show(Text.T.UrlsLine(lineNo, m)));
            var tags = parsed.Tags.Count == 0
                ? (IReadOnlyList<string>)[]
                : parsed.Tags.Select(t => spelling.TryAdd(t, t) ? t : spelling[t]).ToList();
            result.Add(new UrlEntry(lineNo, parsed.Url, group) { Tags = tags, Note = parsed.Note });
        }

        return result;
    }

    /// <summary>
    /// "#tag https://www.threads.com/..." (a tag written before the URL makes the whole line a
    /// comment). Not: "# ..." (a comment on purpose, like the examples in the template), "## ..."
    /// (a group) or "#https://..." (a URL commented out on purpose).
    /// </summary>
    private static bool LooksLikeTagBeforeUrl(string line)
    {
        if (line.Length < 2 || line[1] == '#' || char.IsWhiteSpace(line[1]))
        {
            return false;
        }
        var firstEnd = 1;
        while (firstEnd < line.Length && !char.IsWhiteSpace(line[firstEnd]))
        {
            firstEnd++;
        }
        return !MentionsThreads(line[..firstEnd]) && MentionsThreads(line[firstEnd..]);
    }

    private static bool MentionsThreads(string s) =>
        s.Contains("threads.com", StringComparison.OrdinalIgnoreCase) || s.Contains("threads.net", StringComparison.OrdinalIgnoreCase);

    /// <summary>
    /// Text of a file the user edited: UTF-8 (with or without BOM) or UTF-16 with BOM; otherwise the
    /// first legacy code page that decodes it without errors (the system ANSI code page on Windows,
    /// then 950, 936, 932). <paramref name="fallback"/> names the code page used, or is null for Unicode.
    /// </summary>
    public static string Decode(byte[] bytes, int? ansiCodePage, out string? fallback)
    {
        fallback = null;
        if (bytes is [0xEF, 0xBB, 0xBF, ..])
        {
            bytes = bytes[3..];
        }
        else if (bytes is [0xFF, 0xFE, ..])
        {
            return Encoding.Unicode.GetString(bytes, 2, bytes.Length - 2);
        }
        else if (bytes is [0xFE, 0xFF, ..])
        {
            return Encoding.BigEndianUnicode.GetString(bytes, 2, bytes.Length - 2);
        }

        try
        {
            return new UTF8Encoding(false, throwOnInvalidBytes: true).GetString(bytes);
        }
        catch (DecoderFallbackException)
        {
        }

        Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
        var candidates = new List<int>();
        if (ansiCodePage is { } a and > 0)
        {
            candidates.Add(a);
        }
        candidates.AddRange([950, 936, 932]);
        foreach (var cp in candidates.Distinct())
        {
            try
            {
                var enc = Encoding.GetEncoding(cp, EncoderFallback.ExceptionFallback, DecoderFallback.ExceptionFallback);
                var text = enc.GetString(bytes);
                fallback = "code page " + cp.ToString(CultureInfo.InvariantCulture);
                return text;
            }
            catch (Exception e) when (e is DecoderFallbackException or ArgumentException or NotSupportedException)
            {
            }
        }

        fallback = "UTF-8 (invalid bytes replaced)";
        return Encoding.UTF8.GetString(bytes);
    }

    /// <summary>
    /// Exclusive lock on .lock so two runs in the same folder never overlap. Returns null when it
    /// cannot be taken. Dispose the returned handle to release.
    /// </summary>
    public IDisposable? TryLock() => Acquire(Environment.MachineName, Environment.ProcessId).Handle;

    /// <summary>
    /// Takes the run lock and writes host, pid and time into .lock. A read-only or unwritable .lock is
    /// deleted and created again. When the file says another computer used this folder less than
    /// 30 minutes ago (a folder synced by OneDrive/Dropbox), the lock is not taken.
    /// </summary>
    public LockResult Acquire(string host, int pid)
    {
        FileStream fs;
        try
        {
            fs = OpenLock();
        }
        catch (UnauthorizedAccessException)
        {
            try
            {
                if (File.Exists(LockPath))
                {
                    File.SetAttributes(LockPath, FileAttributes.Normal);
                    File.Delete(LockPath);
                }
                fs = OpenLock();
            }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException)
            {
                return new LockResult(LockState.Unwritable, null);
            }
        }
        catch (IOException)
        {
            return new LockResult(LockState.Busy, null);
        }

        try
        {
            var (otherHost, otherTime) = ReadLockInfo(fs);
            var now = time.GetUtcNow();
            if (otherHost != null && !string.Equals(otherHost, host, StringComparison.OrdinalIgnoreCase)
                && otherTime is { } t && (now - t).Duration() <= OtherComputerWindow)
            {
                fs.Dispose();
                return new LockResult(LockState.OtherComputer, null, otherHost, t, otherHost);
            }

            var info = Encoding.UTF8.GetBytes(
                $"host={host}\npid={pid.ToString(CultureInfo.InvariantCulture)}\ntime={now.ToString("O", CultureInfo.InvariantCulture)}\n");
            fs.SetLength(0);
            fs.Position = 0;
            fs.Write(info);
            fs.Flush();
            return new LockResult(LockState.Acquired, fs, PreviousHost: otherHost);
        }
        catch (Exception e) when (e is IOException or UnauthorizedAccessException)
        {
            fs.Dispose();
            return new LockResult(LockState.Unwritable, null);
        }
    }

    private FileStream OpenLock() => new(LockPath, FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None);

    private static (string? Host, DateTimeOffset? Time) ReadLockInfo(FileStream fs)
    {
        var buffer = new byte[4096];
        var n = fs.Read(buffer, 0, buffer.Length);
        string? host = null;
        DateTimeOffset? at = null;
        foreach (var raw in Encoding.UTF8.GetString(buffer, 0, n).Split('\n'))
        {
            var line = raw.Trim();
            if (line.StartsWith("host=", StringComparison.Ordinal))
            {
                host = line[5..].Trim();
            }
            else if (line.StartsWith("time=", StringComparison.Ordinal)
                     && DateTimeOffset.TryParse(line[5..].Trim(), CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind, out var t))
            {
                at = t;
            }
        }

        return (string.IsNullOrEmpty(host) ? null : host, at);
    }

    public void Log(string message)
    {
        try
        {
            if (files.Exists(LogPath) && files.Length(LogPath) > LogMaxBytes)
            {
                files.Move(LogPath, Path.Combine(Directory, "log.1.txt"), overwrite: true);
            }
            var stamp = TimeZoneInfo.ConvertTime(time.GetUtcNow(), time.LocalTimeZone)
                .ToString(FolderStore.TimeFormat, CultureInfo.InvariantCulture);
            files.AppendAllText(LogPath, stamp + " " + Text.OneLine(message) + Environment.NewLine);
        }
        catch (Exception e) when (e is IOException or UnauthorizedAccessException)
        {
            // Logging must never stop a run.
        }
    }
}
