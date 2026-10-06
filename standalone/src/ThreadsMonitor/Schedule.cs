using System.Diagnostics;
using System.Security;
using System.Security.Cryptography;
using System.Text;
using System.Xml.Linq;
using ThreadsMonitor.Store;

namespace ThreadsMonitor;

public enum OsKind
{
    Windows,
    MacOS,
    Linux,
    Other,
}

public sealed record ProcessResult(int ExitCode, string StdOut, string StdErr);

/// <summary>Runs external programs (schtasks, launchctl, crontab). Tests replace it with a fake.</summary>
public interface IProcessRunner
{
    ProcessResult Run(string fileName, IReadOnlyList<string> arguments, string? standardInput = null);
}

public sealed class RealProcessRunner : IProcessRunner
{
    public ProcessResult Run(string fileName, IReadOnlyList<string> arguments, string? standardInput = null)
    {
        var psi = new ProcessStartInfo(fileName)
        {
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            RedirectStandardInput = standardInput != null,
            UseShellExecute = false,
            CreateNoWindow = true,
        };
        foreach (var a in arguments)
        {
            psi.ArgumentList.Add(a);
        }

        using var p = Process.Start(psi) ?? throw new InvalidOperationException("Could not start " + fileName);
        if (standardInput != null)
        {
            p.StandardInput.Write(standardInput);
            p.StandardInput.Close();
        }
        var stdout = p.StandardOutput.ReadToEndAsync();
        var stderr = p.StandardError.ReadToEndAsync();
        p.WaitForExit();
        return new ProcessResult(p.ExitCode, stdout.Result, stderr.Result);
    }
}

/// <summary>
/// Installs / removes a "run every 10 minutes" schedule for one work folder.
///  - Windows: Task Scheduler (schtasks /Create /XML), task name ThreadsMonitor-{id}. The task XML
///    lets it run on battery, keeps it running when the laptop is unplugged, and catches up a missed
///    start (StartWhenAvailable).
///  - macOS: ~/Library/LaunchAgents/local.threadsmonitor.{id}.plist loaded with launchctl.
///  - Linux: a crontab line tagged "# ThreadsMonitor-{id}", written only after the user agrees.
/// {id} is derived from the work folder path, so several folders can each have their own schedule.
/// </summary>
public sealed class ScheduleInstaller(
    IProcessRunner runner,
    IFileOps files,
    OsKind os,
    string exePath,
    string workDir,
    bool customDir,
    string homeDir,
    TextWriter output,
    Func<string?> readLine,
    TimeProvider? time = null)
{
    public const int IntervalMinutes = 10;

    private Strings T => Text.T;

    public string Id
    {
        get
        {
            var full = Path.GetFullPath(workDir).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
            var key = os == OsKind.Windows ? full.ToLowerInvariant() : full;
            var hash = SHA256.HashData(Encoding.UTF8.GetBytes(key));
            return Convert.ToHexString(hash)[..8].ToLowerInvariant();
        }
    }

    public string TaskName => "ThreadsMonitor-" + Id;

    public string PlistLabel => "local.threadsmonitor." + Id;

    public string PlistPath => Path.Combine(homeDir, "Library", "LaunchAgents", PlistLabel + ".plist");

    /// <summary>Arguments after the executable: "run", plus --dir only when a custom folder was given.</summary>
    public IReadOnlyList<string> RunArguments => customDir ? ["run", "--dir", Path.GetFullPath(workDir)] : ["run"];

    public int Install() => os switch
    {
        OsKind.Windows => InstallWindows(),
        OsKind.MacOS => InstallMac(),
        OsKind.Linux => InstallCron(),
        _ => Unsupported(),
    };

    public int Uninstall() => os switch
    {
        OsKind.Windows => UninstallWindows(),
        OsKind.MacOS => UninstallMac(),
        OsKind.Linux => UninstallCron(),
        _ => Unsupported(),
    };

    private int Unsupported()
    {
        output.WriteLine(T.ScheduleUnsupported);
        return 1;
    }

    // ---------- Windows ----------

    public string WindowsTaskCommand()
    {
        var sb = new StringBuilder();
        sb.Append('"').Append(exePath).Append('"');
        foreach (var a in RunArguments)
        {
            sb.Append(' ').Append(a == "run" || a.StartsWith("--", StringComparison.Ordinal) ? a : "\"" + a.TrimEnd('\\') + "\"");
        }
        return sb.ToString();
    }

    /// <summary>The command line arguments of the task (everything after the executable).</summary>
    public string WindowsTaskArguments() =>
        string.Join(" ", RunArguments.Select(a => a == "run" || a.StartsWith("--", StringComparison.Ordinal) ? a : "\"" + a.TrimEnd('\\') + "\""));

    public static readonly XNamespace TaskNs = "http://schemas.microsoft.com/windows/2004/02/mit/task";

    /// <summary>Task Scheduler definition: every 10 minutes, indefinitely, also on battery.</summary>
    public string TaskXml()
    {
        var ns = TaskNs;
        var start = (time ?? TimeProvider.System).GetLocalNow();
        var doc = new XDocument(
            new XElement(ns + "Task", new XAttribute("version", "1.2"),
                new XElement(ns + "RegistrationInfo",
                    new XElement(ns + "Description", "ThreadsMonitor: run every " + IntervalMinutes + " minutes")),
                new XElement(ns + "Triggers",
                    new XElement(ns + "TimeTrigger",
                        new XElement(ns + "Repetition",
                            new XElement(ns + "Interval", "PT" + IntervalMinutes + "M"),
                            new XElement(ns + "StopAtDurationEnd", "false")),
                        new XElement(ns + "StartBoundary", start.ToString("yyyy-MM-dd'T'HH:mm:ss", System.Globalization.CultureInfo.InvariantCulture)),
                        new XElement(ns + "Enabled", "true"))),
                new XElement(ns + "Settings",
                    new XElement(ns + "MultipleInstancesPolicy", "IgnoreNew"),
                    new XElement(ns + "DisallowStartIfOnBatteries", "false"),
                    new XElement(ns + "StopIfGoingOnBatteries", "false"),
                    new XElement(ns + "AllowHardTerminate", "true"),
                    new XElement(ns + "StartWhenAvailable", "true"),
                    new XElement(ns + "RunOnlyIfNetworkAvailable", "false"),
                    new XElement(ns + "IdleSettings",
                        new XElement(ns + "StopOnIdleEnd", "false"),
                        new XElement(ns + "RestartOnIdle", "false")),
                    new XElement(ns + "AllowStartOnDemand", "true"),
                    new XElement(ns + "Enabled", "true"),
                    new XElement(ns + "Hidden", "false"),
                    new XElement(ns + "RunOnlyIfIdle", "false"),
                    new XElement(ns + "WakeToRun", "false"),
                    new XElement(ns + "ExecutionTimeLimit", "PT1H"),
                    new XElement(ns + "Priority", "7")),
                new XElement(ns + "Actions",
                    new XElement(ns + "Exec",
                        new XElement(ns + "Command", exePath),
                        new XElement(ns + "Arguments", WindowsTaskArguments()),
                        new XElement(ns + "WorkingDirectory", Path.GetFullPath(workDir))))));

        return "<?xml version=\"1.0\" encoding=\"UTF-16\"?>\r\n" + doc.ToString();
    }

    private int InstallWindows()
    {
        // schtasks reads the definition from a UTF-16 file.
        var xmlPath = Path.Combine(Path.GetTempPath(), TaskName + ".xml");
        try
        {
            files.WriteAllBytes(xmlPath, [.. Encoding.Unicode.GetPreamble(), .. Encoding.Unicode.GetBytes(TaskXml())]);
        }
        catch (Exception e) when (e is IOException or UnauthorizedAccessException)
        {
            output.WriteLine(T.ScheduleFailed(Text.OneLine(e.Message)));
            return 1;
        }

        ProcessResult r;
        try
        {
            r = runner.Run("schtasks", ["/Create", "/F", "/TN", TaskName, "/XML", xmlPath]);
        }
        finally
        {
            try
            {
                files.Delete(xmlPath);
            }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException)
            {
            }
        }
        return Report(r, $"Task Scheduler \"{TaskName}\"");
    }

    private int UninstallWindows()
    {
        var r = runner.Run("schtasks", ["/Delete", "/F", "/TN", TaskName]);
        if (r.ExitCode != 0)
        {
            output.WriteLine(T.ScheduleNotFound);
            return 0;
        }
        output.WriteLine(T.ScheduleUninstalled);
        return 0;
    }

    // ---------- macOS ----------

    public string PlistXml()
    {
        var args = new StringBuilder();
        args.Append("    <string>").Append(SecurityElement.Escape(exePath)).Append("</string>\n");
        foreach (var a in RunArguments)
        {
            args.Append("    <string>").Append(SecurityElement.Escape(a)).Append("</string>\n");
        }

        return $"""
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
              <key>Label</key>
              <string>{PlistLabel}</string>
              <key>ProgramArguments</key>
              <array>
            {args.ToString().TrimEnd('\n')}
              </array>
              <key>WorkingDirectory</key>
              <string>{SecurityElement.Escape(Path.GetFullPath(workDir))}</string>
              <key>StartInterval</key>
              <integer>{IntervalMinutes * 60}</integer>
              <key>RunAtLoad</key>
              <true/>
              <key>StandardOutPath</key>
              <string>/dev/null</string>
              <key>StandardErrorPath</key>
              <string>/dev/null</string>
            </dict>
            </plist>

            """;
    }

    private int InstallMac()
    {
        try
        {
            files.CreateDirectory(Path.GetDirectoryName(PlistPath)!);
            files.WriteAllText(PlistPath, PlistXml(), withBom: false);
        }
        catch (Exception e) when (e is IOException or UnauthorizedAccessException)
        {
            output.WriteLine(T.ScheduleFailed(e.Message));
            return 1;
        }

        runner.Run("launchctl", ["unload", PlistPath]); // ignore: not loaded yet
        var r = runner.Run("launchctl", ["load", "-w", PlistPath]);
        return Report(r, PlistPath);
    }

    private int UninstallMac()
    {
        if (!files.Exists(PlistPath))
        {
            output.WriteLine(T.ScheduleNotFound);
            return 0;
        }
        runner.Run("launchctl", ["unload", "-w", PlistPath]);
        files.Delete(PlistPath);
        output.WriteLine(T.ScheduleUninstalled);
        return 0;
    }

    // ---------- Linux (cron) ----------

    private string CronMarker => "# " + TaskName;

    public string CronLine()
    {
        static string Quote(string s) => "'" + s.Replace("'", "'\\''").Replace("%", "\\%") + "'";
        var cmd = string.Join(" ", new[] { Quote(exePath) }.Concat(RunArguments.Select(a => a.StartsWith("--") || a == "run" ? a : Quote(a))));
        return $"*/{IntervalMinutes} * * * * {cmd} >/dev/null 2>&1 {CronMarker}";
    }

    private int InstallCron()
    {
        var line = CronLine();
        output.WriteLine(T.CronLine(line));
        output.Write(T.CronAsk);
        var answer = readLine()?.Trim().ToLowerInvariant();
        if (answer is not ("y" or "yes"))
        {
            output.WriteLine();
            output.WriteLine(T.CronSkipped);
            return 0;
        }

        var current = ReadCrontab();
        if (current == null)
        {
            return 1;
        }
        var lines = current.Where(l => !l.Contains(CronMarker, StringComparison.Ordinal)).Append(line);
        var r = runner.Run("crontab", ["-"], string.Join("\n", lines) + "\n");
        if (r.ExitCode != 0)
        {
            output.WriteLine(T.ScheduleFailed(Text.OneLine(r.StdErr)));
            return 1;
        }
        output.WriteLine(T.CronWritten);
        return 0;
    }

    private int UninstallCron()
    {
        var current = ReadCrontab();
        if (current == null)
        {
            return 1;
        }
        if (!current.Any(l => l.Contains(CronMarker, StringComparison.Ordinal)))
        {
            output.WriteLine(T.ScheduleNotFound);
            return 0;
        }
        var kept = current.Where(l => !l.Contains(CronMarker, StringComparison.Ordinal)).ToList();
        var r = runner.Run("crontab", ["-"], kept.Count == 0 ? "" : string.Join("\n", kept) + "\n");
        if (r.ExitCode != 0)
        {
            output.WriteLine(T.ScheduleFailed(Text.OneLine(r.StdErr)));
            return 1;
        }
        output.WriteLine(T.CronRemoved);
        return 0;
    }

    /// <summary>Current crontab lines; empty when the user has none; null on a real error.</summary>
    private List<string>? ReadCrontab()
    {
        ProcessResult r;
        try
        {
            r = runner.Run("crontab", ["-l"]);
        }
        catch (Exception e)
        {
            output.WriteLine(T.ScheduleFailed(Text.OneLine(e.Message)));
            return null;
        }
        if (r.ExitCode != 0)
        {
            // "no crontab for user" is normal for a first install.
            return r.StdErr.Contains("no crontab", StringComparison.OrdinalIgnoreCase) ? [] : Fail(r.StdErr);
        }

        return r.StdOut.Replace("\r\n", "\n").Split('\n').Where(l => l.Length > 0).ToList();
    }

    private List<string>? Fail(string err)
    {
        output.WriteLine(T.ScheduleFailed(Text.OneLine(err)));
        return null;
    }

    private int Report(ProcessResult r, string how)
    {
        if (r.ExitCode != 0)
        {
            output.WriteLine(T.ScheduleFailed(Text.OneLine(r.StdErr + " " + r.StdOut)));
            return 1;
        }
        output.WriteLine(T.ScheduleInstalled(how));
        return 0;
    }
}
