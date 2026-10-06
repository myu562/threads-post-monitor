using System.Globalization;
using System.Net;
using ThreadsMonitor.Core;
using ThreadsMonitor.Store;

namespace ThreadsMonitor.Tests;

/// <summary>Runs the whole app against a temporary folder, a fake clock and a fake Threads.</summary>
public sealed class Harness : IDisposable
{
    public static readonly DateTimeOffset Start = new(2026, 10, 5, 0, 0, 0, TimeSpan.Zero);

    public TempDir Dir { get; } = new();
    public FakeTime Time { get; } = new(Start);
    public LockableFileOps Files { get; } = new();
    public StringWriter Output { get; private set; } = new();
    public FakeProcessRunner Processes { get; } = new();
    public List<string> Requests { get; } = [];
    public List<TimeSpan> Sleeps { get; } = [];

    /// <summary>Page per shortcode; missing ones answer 404.</summary>
    public Dictionary<string, Func<HttpResponseMessage>> Pages { get; } = new(StringComparer.Ordinal);

    /// <summary>Share code -> Location header.</summary>
    public Dictionary<string, string> Shares { get; } = new(StringComparer.Ordinal);

    public string Lang { get; set; } = "en";

    /// <summary>OsKind.Windows turns on the Windows path length limit.</summary>
    public OsKind Os { get; set; } = OsKind.Linux;

    /// <summary>Path limit relative to the temp root, so tests do not depend on how long TMPDIR is.</summary>
    public int MaxPathLength { get; set; } = Naming.MaxPathLength;

    public string MachineName { get; set; } = "TESTPC";

    /// <summary>True: a person runs it in a console window. False (default): like the schedule.</summary>
    public bool Interactive { get; set; }

    public string Root => Dir.Path;

    public string PathOf(params string[] parts) => Path.Combine([Root, .. parts]);

    public void Urls(string text) => File.WriteAllText(PathOf("urls.txt"), text);

    public void Page(string code, DateTimeOffset takenAt, long? views = 100, long likes = 10, string user = "example_user", bool hidden = false) =>
        Pages[code] = () => new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(Html(code, takenAt, views, likes, user, hidden)) };

    public static string Html(string code, DateTimeOffset takenAt, long? views, long likes, string user, bool hidden)
    {
        var gating = views.HasValue ? ",\"view_counts\":" + views.Value.ToString(CultureInfo.InvariantCulture) : "";
        const string template = """
            <!DOCTYPE html><html><head>
            <meta property="og:description" content="Synthetic test post." />
            <meta property="og:url" content="https://www.threads.com/@USER/post/CODE" />
            </head><body>
            <script type="application/json" data-sjs>{"require":[["ScheduledServerJS","handle",null,[{"__bbox":{"define":[["BarcelonaLoggedOutExpansionGating",[],{"enable_nav":trueGATING},9001]]}}]]]}</script>
            <script type="application/json" data-sjs>{"data":{"media":{"pk":"1","code":"CODE","user":{"username":"USER"},"text_post_app_info":{"direct_reply_count":3,"repost_count":2,"quote_count":1,"reshare_count":null},"like_count":LIKES,"taken_at":TAKEN,"like_and_view_counts_disabled":HIDDEN}}}</script>
            </body></html>
            """;
        return template
            .Replace("GATING", gating)
            .Replace("CODE", code)
            .Replace("USER", user)
            .Replace("LIKES", likes.ToString(CultureInfo.InvariantCulture))
            .Replace("TAKEN", takenAt.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture))
            .Replace("HIDDEN", hidden ? "true" : "false");
    }

    private HttpResponseMessage Respond(HttpRequestMessage req)
    {
        var url = req.RequestUri!.ToString();
        Requests.Add(url);
        const string page = "https://www.threads.com/t/";
        const string share = "https://www.threads.com/share/";
        if (url.StartsWith(page, StringComparison.Ordinal) && Pages.TryGetValue(url[page.Length..], out var make))
        {
            return make();
        }
        if (url.StartsWith(share, StringComparison.Ordinal) && Shares.TryGetValue(url[share.Length..].TrimEnd('/'), out var location))
        {
            var r = new HttpResponseMessage(HttpStatusCode.Found);
            r.Headers.Location = new Uri(location);
            return r;
        }

        return new HttpResponseMessage(HttpStatusCode.NotFound) { Content = new StringContent("Not Found") };
    }

    public AppEnvironment Env() => new()
    {
        Out = Output,
        Time = Time,
        Files = Files,
        BaseDirectory = Root,
        ExePath = Path.Combine(Root, "ThreadsMonitor"),
        Os = Os,
        MaxPathLength = MaxPathLength,
        MachineName = MachineName,
        ProcessId = 4242,
        HomeDirectory = PathOf("home"),
        HttpFactory = s => ThreadsHttp.FromHandler(new FakeHandler(Respond), s.UserAgent, TimeSpan.FromSeconds(s.TimeoutSeconds)),
        Processes = Processes,
        UiCulture = CultureInfo.InvariantCulture,
        Sleep = Sleeps.Add,
        Interactive = Interactive,
    };

    public int Run(params string[] args)
    {
        Output = new StringWriter();
        // --lang first, so that "search -- <words>" keeps only the test's words after "--".
        return new App(Env()).RunAsync(["--lang", Lang, .. args]).GetAwaiter().GetResult();
    }

    public string Text => Output.ToString();

    /// <summary>All post folders (relative, '/' separated) that contain snapshots.csv.</summary>
    public List<string> PostFolders() =>
        Directory.GetFiles(Root, FolderStore.DataFile, SearchOption.AllDirectories)
            .Select(f => Path.GetRelativePath(Root, Path.GetDirectoryName(f)!).Replace('\\', '/'))
            .OrderBy(f => f, StringComparer.Ordinal)
            .ToList();

    public List<Dictionary<string, string>> ReadCsv(string relativePath) =>
        Csv.ParseWithHeader(File.ReadAllText(Path.Combine(Root, relativePath)));

    public List<Dictionary<string, string>> Index() => ReadCsv("index.csv");

    public void Dispose()
    {
        Text_Reset();
        Dir.Dispose();
    }

    private static void Text_Reset() => ThreadsMonitor.Text.T = Strings.English;
}
