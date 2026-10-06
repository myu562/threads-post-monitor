using System.Text;

namespace ThreadsMonitor.Store;

/// <summary>File access used by the store, so tests can simulate a file locked by Excel.</summary>
public interface IFileOps
{
    bool Exists(string path);
    string ReadAllText(string path);
    byte[] ReadAllBytes(string path);
    void WriteAllBytes(string path, byte[] bytes);
    void AppendAllText(string path, string text);
    void WriteAllText(string path, string text, bool withBom);
    void Move(string source, string destination, bool overwrite);
    void Delete(string path);
    long Length(string path);
    IEnumerable<string> GetFiles(string directory, string pattern);
    void CreateDirectory(string path);
    bool DirectoryExists(string path);
    /// <summary>Full paths of the visible subdirectories (hidden ones are skipped).</summary>
    IEnumerable<string> GetDirectories(string directory);
    void MoveDirectory(string source, string destination);
}

public class RealFileOps : IFileOps
{
    private static readonly UTF8Encoding Utf8NoBom = new(false);
    private static readonly UTF8Encoding Utf8Bom = new(true);

    public virtual bool Exists(string path) => File.Exists(path);

    public virtual string ReadAllText(string path)
    {
        // Allow reading while another program (Excel) has the file open.
        using var fs = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        using var reader = new StreamReader(fs, Utf8NoBom, detectEncodingFromByteOrderMarks: true);
        return reader.ReadToEnd();
    }

    public virtual byte[] ReadAllBytes(string path)
    {
        using var fs = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        using var ms = new MemoryStream();
        fs.CopyTo(ms);
        return ms.ToArray();
    }

    public virtual void WriteAllBytes(string path, byte[] bytes) => File.WriteAllBytes(path, bytes);

    public virtual void AppendAllText(string path, string text) => File.AppendAllText(path, text, Utf8NoBom);

    public virtual void WriteAllText(string path, string text, bool withBom) =>
        File.WriteAllText(path, text, withBom ? Utf8Bom : Utf8NoBom);

    public virtual void Move(string source, string destination, bool overwrite) => File.Move(source, destination, overwrite);

    public virtual void Delete(string path) => File.Delete(path);

    public virtual long Length(string path) => new FileInfo(path).Length;

    public virtual IEnumerable<string> GetFiles(string directory, string pattern) =>
        Directory.Exists(directory) ? Directory.GetFiles(directory, pattern) : [];

    public virtual void CreateDirectory(string path) => Directory.CreateDirectory(path);

    public virtual bool DirectoryExists(string path) => Directory.Exists(path);

    public virtual IEnumerable<string> GetDirectories(string directory)
    {
        if (!Directory.Exists(directory))
        {
            return [];
        }

        return new DirectoryInfo(directory).EnumerateDirectories()
            .Where(d => !d.Name.StartsWith('.') && (d.Attributes & FileAttributes.Hidden) == 0)
            .Select(d => d.FullName)
            .ToList();
    }

    public virtual void MoveDirectory(string source, string destination) => Directory.Move(source, destination);
}
