using System.Net;
using ThreadsMonitor.Store;

namespace ThreadsMonitor.Tests;

/// <summary>
/// HttpMessageHandler that never touches the network: every request is answered by the
/// supplied function and recorded.
/// </summary>
public sealed class FakeHandler(Func<HttpRequestMessage, HttpResponseMessage> respond) : HttpMessageHandler
{
    public List<HttpRequestMessage> Requests { get; } = [];

    public static FakeHandler Html(string body, HttpStatusCode code = HttpStatusCode.OK) =>
        new(_ => new HttpResponseMessage(code) { Content = new StringContent(body) });

    public static FakeHandler Status(int code, string body = "") =>
        new(_ => new HttpResponseMessage((HttpStatusCode)code) { Content = new StringContent(body) });

    public static FakeHandler Throws(Exception e) => new(_ => throw e);

    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        Requests.Add(request);
        return Task.FromResult(respond(request));
    }
}

public sealed class FakeTime : TimeProvider
{
    public static readonly TimeZoneInfo Plus8 =
        TimeZoneInfo.CreateCustomTimeZone("Test+08", TimeSpan.FromHours(8), "Test+08", "Test+08");

    public FakeTime(DateTimeOffset utcNow, TimeZoneInfo? zone = null)
    {
        UtcNow = utcNow;
        Zone = zone ?? Plus8;
    }

    public DateTimeOffset UtcNow { get; set; }
    public TimeZoneInfo Zone { get; set; }

    public override DateTimeOffset GetUtcNow() => UtcNow;

    public override TimeZoneInfo LocalTimeZone => Zone;

    public void Advance(TimeSpan by) => UtcNow += by;
}

/// <summary>Real file IO, except that paths in Locked behave like a file Excel has open.</summary>
public sealed class LockableFileOps : RealFileOps
{
    public HashSet<string> Locked { get; } = new(StringComparer.Ordinal);

    public int FailedWrites { get; private set; }

    private void Check(string path)
    {
        if (Locked.Contains(Path.GetFileName(path)))
        {
            FailedWrites++;
            throw new IOException("The process cannot access the file because it is being used by another process.");
        }
    }

    public override void AppendAllText(string path, string text)
    {
        Check(path);
        base.AppendAllText(path, text);
    }

    public override void WriteAllText(string path, string text, bool withBom)
    {
        Check(path);
        base.WriteAllText(path, text, withBom);
    }

    public override void Move(string source, string destination, bool overwrite)
    {
        Check(destination);
        base.Move(source, destination, overwrite);
    }

    /// <summary>Behave like Windows/macOS: folder names that differ only in case are the same folder.</summary>
    public bool CaseInsensitive { get; set; }

    public List<string> CreatedDirectories { get; } = [];

    public override void CreateDirectory(string path)
    {
        if (CaseInsensitive && !Directory.Exists(path))
        {
            var parent = Path.GetDirectoryName(path)!;
            var name = Path.GetFileName(path);
            if (Directory.Exists(parent) && Directory.GetDirectories(parent)
                    .Any(d => string.Equals(Path.GetFileName(d), name, StringComparison.OrdinalIgnoreCase)))
            {
                return; // the existing folder is reused, exactly what a case-insensitive file system does
            }
        }
        CreatedDirectories.Add(path);
        base.CreateDirectory(path);
    }

    public bool FailDirectoryMoves { get; set; }

    public override void MoveDirectory(string source, string destination)
    {
        if (FailDirectoryMoves)
        {
            throw new IOException("in use");
        }
        base.MoveDirectory(source, destination);
    }
}

public sealed class FakeProcessRunner : IProcessRunner
{
    public List<(string File, string[] Args, string? Stdin)> Calls { get; } = [];

    public Func<string, string[], ProcessResult> Respond { get; set; } = (_, _) => new ProcessResult(0, "", "");

    public ProcessResult Run(string fileName, IReadOnlyList<string> arguments, string? standardInput = null)
    {
        var args = arguments.ToArray();
        Calls.Add((fileName, args, standardInput));
        return Respond(fileName, args);
    }
}

/// <summary>A unique temporary folder, removed afterwards.</summary>
public sealed class TempDir : IDisposable
{
    private const string Prefix = "ThreadsMonitorTests-";

    public TempDir()
    {
        Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), Prefix + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(Path);
    }

    public string Path { get; }

    public string File(string name) => System.IO.Path.Combine(Path, name);

    public void Dispose()
    {
        // Only ever delete the folder this class created.
        if (System.IO.Path.GetFileName(Path).StartsWith(Prefix, StringComparison.Ordinal) && Directory.Exists(Path))
        {
            Directory.Delete(Path, recursive: true);
        }
    }
}

public static class Fixtures
{
    public const string Code = "DFakeP0st01";

    public static string Read(string name) =>
        System.IO.File.ReadAllText(System.IO.Path.Combine(AppContext.BaseDirectory, "Fixtures", name));
}
