using System.Globalization;
using System.Text;
using ThreadsMonitor;
using ThreadsMonitor.Store;

try
{
    Console.OutputEncoding = Encoding.UTF8;
}
catch (IOException)
{
}

var interactive = !Console.IsInputRedirected && !Console.IsOutputRedirected;
var os = OperatingSystem.IsWindows() ? OsKind.Windows
    : OperatingSystem.IsMacOS() ? OsKind.MacOS
    : OperatingSystem.IsLinux() ? OsKind.Linux
    : OsKind.Other;

var env = new AppEnvironment
{
    Out = Console.Out,
    Time = TimeProvider.System,
    Files = new RealFileOps(),
    BaseDirectory = AppContext.BaseDirectory,
    ExePath = Environment.ProcessPath ?? Path.Combine(AppContext.BaseDirectory, "ThreadsMonitor"),
    Os = os,
    HomeDirectory = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile),
    HttpFactory = s => ThreadsMonitor.Core.ThreadsHttp.CreateDefault(s.UserAgent, TimeSpan.FromSeconds(s.TimeoutSeconds)),
    Processes = new RealProcessRunner(),
    UiCulture = CultureInfo.CurrentUICulture,
    Interactive = interactive,
    WaitForKey = () => Console.ReadKey(intercept: true),
    ReadLine = Console.ReadLine,
    AnsiCodePage = OperatingSystem.IsWindows() ? CultureInfo.CurrentCulture.TextInfo.ANSICodePage : null,
};

try
{
    return await new App(env).RunAsync(args);
}
catch (Exception e)
{
    Console.Error.WriteLine("ThreadsMonitor: " + Text.OneLine(e.GetType().Name + ": " + e.Message));
    if (interactive)
    {
        Console.Error.WriteLine(Text.T.PressAnyKey);
        Console.ReadKey(intercept: true);
    }
    return 1;
}
