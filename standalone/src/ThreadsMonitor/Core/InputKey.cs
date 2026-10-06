using System.Security.Cryptography;
using System.Text;

namespace ThreadsMonitor.Core;

/// <summary>
/// What snapshots.csv keeps about the urls.txt line a post came from, instead of the line itself
/// (which can carry tracking parameters or a private share code): the first 16 hex characters of the
/// SHA-256 of the normalized input. A post URL normalizes to its shortcode, a share link to its share
/// code. Only used to recognize a urls.txt line that was already resolved.
/// </summary>
public static class InputKey
{
    public static string? Normalize(string input) =>
        ThreadsUrl.Parse(input)?.Shortcode ?? ThreadsUrl.ParseShare(input);

    /// <summary>The key of a urls.txt line, or null when it is neither a post URL nor a share link.</summary>
    public static string? Of(string input) => Normalize(input) is { } n ? Hash(n) : null;

    public static string Hash(string normalized) =>
        Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(normalized)))[..16].ToLowerInvariant();
}
