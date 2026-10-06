using System.Globalization;
using ThreadsMonitor.Store;

namespace ThreadsMonitor.Tests;

public class NamingTests
{
    [Theory]
    [InlineData("a\\b/c:d*e?f\"g<h>i|j", "a_b_c_d_e_f_g_h_i_j")]
    [InlineData("  name.. ", "name")]
    [InlineData("tab\there", "tab_here")]
    [InlineData("CON", "_CON")]
    [InlineData("con.txt", "_con.txt")]
    [InlineData("COM1", "_COM1")]
    [InlineData("lpt9", "_lpt9")]
    [InlineData("COM10", "COM10")]
    [InlineData("Console", "Console")]
    [InlineData("...", "_")]
    [InlineData("客戶A", "客戶A")]
    public void Sanitize(string input, string expected) => Assert.Equal(expected, Naming.Sanitize(input));

    [Fact]
    public void Sanitize_normalizes_to_nfc()
    {
        var decomposed = "Café"; // e + combining acute accent
        Assert.Equal("Café", Naming.Sanitize(decomposed));
    }

    [Fact]
    public void Group_names_are_limited_to_40_characters()
    {
        var name = Naming.Sanitize(new string('客', 50), Naming.MaxGroupLength);
        Assert.Equal(40, name.Length);
        Assert.Equal("abc", Naming.Sanitize("abc" + new string(' ', 38) + "x", 40)); // trailing space trimmed after cut
    }

    [Fact]
    public void Post_folder_name()
    {
        var local = new DateTimeOffset(2026, 10, 5, 6, 30, 0, TimeSpan.FromHours(8));
        Assert.Equal("2026-10-05_06h_@example_user_DFakeP0st01", Naming.PostFolder(local, "example_user", "DFakeP0st01"));
        Assert.Equal("2026-10-05_06h_DFakeP0st01", Naming.PostFolder(local, null, "DFakeP0st01"));
        Assert.Equal("0000-00-00_00h_@example_user_DFakeP0st01", Naming.PostFolder(null, "example_user", "DFakeP0st01"));
        Assert.Equal("2026-10-05_DFakeP0st01", Naming.ShortPostFolder(local, "DFakeP0st01"));
    }

    [Fact]
    public void Unique_is_case_insensitive()
    {
        Assert.Equal("a", Naming.Unique("a", ["b"]));
        Assert.Equal("Abc_2", Naming.Unique("Abc", ["abc"]));
        Assert.Equal("Abc_3", Naming.Unique("Abc", ["ABC", "abc_2"]));
    }

    [Theory]
    [InlineData("封存", true)]
    [InlineData("archive", true)]
    [InlineData("ARCHIVE", true)]
    [InlineData("archives", false)]
    [InlineData("2026 封存區", false)]
    public void Archive_names(string name, bool archived) => Assert.Equal(archived, Naming.IsArchiveName(name));
}

public class CsvTests
{
    [Fact]
    public void Quotes_only_when_needed_and_null_is_empty()
    {
        Assert.Equal("a,,\"b,c\",\"say \"\"hi\"\"\",\"x\ny\"\r\n", Csv.Line(["a", null, "b,c", "say \"hi\"", "x\ny"]));
    }

    [Fact]
    public void Parse_round_trips_and_strips_bom()
    {
        var text = "\uFEFF" + Csv.Line(["h1", "h2"]) + Csv.Line(["a,b", "line1\nline2"]) + Csv.Line(["", "z"]);
        var rows = Csv.ParseWithHeader(text);
        Assert.Equal(2, rows.Count);
        Assert.Equal("a,b", rows[0]["h1"]);
        Assert.Equal("line1\nline2", rows[0]["h2"]);
        Assert.Equal("", rows[1]["h1"]);
    }
}

public class WorkFolderTests
{
    [Fact]
    public void Urls_groups_and_comments()
    {
        using var dir = new TempDir();
        File.WriteAllText(dir.File("urls.txt"), string.Join("\n",
            "\uFEFF# comment",
            "https://www.threads.com/t/DFakeP0st01",
            "## Client A",
            "https://www.threads.com/t/DFakeP0st02",
            "#not a group",
            "###also a comment",
            "##\tAutumn sale  ",
            "",
            "  https://www.threads.com/t/DFakeShr0x9  ",
            "## ",
            "https://www.threads.com/t/CFake_Old01"));
        var urls = new WorkFolder(dir.Path, new RealFileOps(), TimeProvider.System).ReadUrls();

        Assert.Equal(
        [
            new UrlEntry(2, "https://www.threads.com/t/DFakeP0st01", null),
            new UrlEntry(4, "https://www.threads.com/t/DFakeP0st02", "Client A"),
            new UrlEntry(9, "https://www.threads.com/t/DFakeShr0x9", "Autumn sale"),
            new UrlEntry(11, "https://www.threads.com/t/CFake_Old01", null),
        ], urls);
    }

    [Fact]
    public void Log_rotates_after_one_megabyte()
    {
        using var dir = new TempDir();
        File.WriteAllText(dir.File("log.txt"), new string('x', 1024 * 1024 + 1));
        new WorkFolder(dir.Path, new RealFileOps(), new FakeTime(Harness.Start)).Log("hello");
        Assert.True(File.Exists(dir.File("log.1.txt")));
        Assert.EndsWith("hello" + Environment.NewLine, File.ReadAllText(dir.File("log.txt")));
        Assert.StartsWith("2026-10-05T08:00:00+08:00", File.ReadAllText(dir.File("log.txt")));
    }

    [Fact]
    public void Settings_ini_overrides_defaults_and_ignores_garbage()
    {
        var s = Settings.Parse("""
            [threads]
            ; comment
            user_agent = TestAgent/2.0
            sleep_seconds = 0.5
            retry_minutes = 30
            max_consecutive_failures = 0
            nonsense
            """);
        Assert.Equal("TestAgent/2.0", s.UserAgent);
        Assert.Equal(0.5, s.SleepSeconds);
        Assert.Equal(30, s.Tracker.RetryMinutes);
        Assert.Equal(5, s.Tracker.MaxConsecutiveFailures); // out of range -> default
        Assert.Equal(30, s.TimeoutSeconds);
        Assert.Equal(Settings.DefaultUserAgent, Settings.Parse(null).UserAgent);
    }
}

public class ScheduleInstallerTests
{
    private static (ScheduleInstaller Installer, FakeProcessRunner Runner, StringWriter Out, TempDir Dir) Make(
        OsKind os, bool customDir = false, string? answer = null)
    {
        var dir = new TempDir();
        var runner = new FakeProcessRunner();
        var output = new StringWriter();
        var exe = Path.Combine(dir.Path, "app dir", "ThreadsMonitor");
        var installer = new ScheduleInstaller(runner, new RealFileOps(), os, exe, Path.Combine(dir.Path, "work"), customDir,
            Path.Combine(dir.Path, "home"), output, () => answer);
        return (installer, runner, output, dir);
    }

    [Fact]
    public void Windows_uses_schtasks_every_10_minutes()
    {
        var (installer, runner, output, dir) = Make(OsKind.Windows);
        using (dir)
        {
            byte[]? xmlBytes = null;
            runner.Respond = (file, args) =>
            {
                if (args.Contains("/XML"))
                {
                    xmlBytes = File.ReadAllBytes(args[Array.IndexOf(args, "/XML") + 1]); // read while schtasks would
                }
                return new ProcessResult(0, "", "");
            };
            Assert.Equal(0, installer.Install());
            var call = Assert.Single(runner.Calls);
            Assert.Equal("schtasks", call.File);
            Assert.Equal(["/Create", "/F", "/TN", installer.TaskName, "/XML"], call.Args[..5]);
            Assert.False(File.Exists(call.Args[5])); // the temporary XML file is removed
            Assert.Equal([0xFF, 0xFE], xmlBytes![..2]); // UTF-16 LE with BOM, as schtasks expects
            Assert.StartsWith("\"", installer.WindowsTaskCommand());
            Assert.EndsWith("\" run", installer.WindowsTaskCommand());
            Assert.Matches("^ThreadsMonitor-[0-9a-f]{8}$", installer.TaskName);

            Assert.Equal(0, installer.Uninstall());
            Assert.Equal(["/Delete", "/F", "/TN", installer.TaskName], runner.Calls[1].Args);
        }
    }

    [Fact]
    public void Custom_folder_is_passed_with_dir()
    {
        var (installer, _, _, dir) = Make(OsKind.Windows, customDir: true);
        using (dir)
        {
            Assert.Equal(["run", "--dir", Path.Combine(dir.Path, "work")], installer.RunArguments);
            Assert.Contains("--dir \"", installer.WindowsTaskCommand());
        }
    }

    [Fact]
    public void Mac_writes_launch_agent_and_loads_it()
    {
        var (installer, runner, _, dir) = Make(OsKind.MacOS);
        using (dir)
        {
            Assert.Equal(0, installer.Install());
            var plist = File.ReadAllText(installer.PlistPath);
            Assert.Contains("<integer>600</integer>", plist);
            Assert.Contains("<string>run</string>", plist);
            Assert.Contains(installer.PlistLabel, plist);
            Assert.StartsWith(Path.Combine(dir.Path, "home", "Library", "LaunchAgents"), installer.PlistPath);
            Assert.Equal("launchctl", runner.Calls[^1].File);
            Assert.Equal(["load", "-w", installer.PlistPath], runner.Calls[^1].Args);

            Assert.Equal(0, installer.Uninstall());
            Assert.False(File.Exists(installer.PlistPath));
            Assert.Equal(["unload", "-w", installer.PlistPath], runner.Calls[^1].Args);
        }
    }

    [Fact]
    public void Linux_prints_cron_line_and_writes_only_after_yes()
    {
        var (installer, runner, output, dir) = Make(OsKind.Linux, answer: "n");
        using (dir)
        {
            Assert.Equal(0, installer.Install());
            Assert.Contains("*/10 * * * * '", output.ToString());
            Assert.Empty(runner.Calls);
        }

        var (yes, runner2, _, dir2) = Make(OsKind.Linux, answer: "y");
        using (dir2)
        {
            runner2.Respond = (f, a) => a.SequenceEqual(["-l"])
                ? new ProcessResult(0, "0 1 * * * existing-job\n", "")
                : new ProcessResult(0, "", "");
            Assert.Equal(0, yes.Install());
            var write = runner2.Calls[^1];
            Assert.Equal("crontab", write.File);
            Assert.Equal(["-"], write.Args);
            Assert.Contains("existing-job", write.Stdin);
            Assert.Contains(yes.CronLine(), write.Stdin);
        }
    }

    [Fact]
    public void Linux_first_install_without_crontab_and_uninstall()
    {
        var (installer, runner, output, dir) = Make(OsKind.Linux, answer: "yes");
        using (dir)
        {
            string crontab = "";
            runner.Respond = (f, a) =>
            {
                if (a.SequenceEqual(["-l"]))
                {
                    return crontab.Length == 0 ? new ProcessResult(1, "", "no crontab for user") : new ProcessResult(0, crontab, "");
                }
                crontab = runner.Calls[^1].Stdin ?? "";
                return new ProcessResult(0, "", "");
            };

            Assert.Equal(0, installer.Install());
            Assert.Contains("# " + installer.TaskName, crontab);

            Assert.Equal(0, installer.Uninstall());
            Assert.DoesNotContain(installer.TaskName, crontab);
            Assert.Contains("Removed", output.ToString());
        }
    }

    [Fact]
    public void Install_schedule_command_never_touches_the_real_system()
    {
        using var h = new Harness();
        Assert.Equal(0, h.Run("install-schedule")); // no answer -> nothing written
        Assert.Empty(h.Processes.Calls);
        Assert.Contains("crontab -e", h.Text);
    }
}
