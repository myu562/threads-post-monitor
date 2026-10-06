using System.Numerics;
using System.Text.RegularExpressions;

namespace ThreadsMonitor.Core;

/// <summary>
/// Threads shortcode decoding.
///
/// pk = sum of index(c) * 64^k over a URL-safe base64 alphabet, most significant char first.
/// created_ms = (pk &gt;&gt; 23) + 1314220021721.
///
/// CreatedAt() is the id creation time, not necessarily the publish time (it can be a few
/// seconds earlier). Use it only as a fallback when the page's taken_at is unavailable.
/// </summary>
public static class Shortcode
{
    public const string Alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";

    public const long EpochMs = 1314220021721;

    /// <summary>Regex fragment (no anchors) for a syntactically valid shortcode.</summary>
    public const string Pattern = "[A-Za-z0-9_-]{5,64}";

    private static readonly Regex ValidRe = new("^" + Pattern + @"\z", RegexOptions.CultureInvariant);

    public static bool IsValid(string code) => ValidRe.IsMatch(code);

    public static BigInteger ToPk(string code)
    {
        if (string.IsNullOrEmpty(code))
        {
            throw new ArgumentException("Empty shortcode.", nameof(code));
        }

        var n = BigInteger.Zero;
        foreach (var ch in code)
        {
            var i = Alphabet.IndexOf(ch);
            if (i < 0)
            {
                throw new ArgumentException("Invalid shortcode character.", nameof(code));
            }
            n = n * 64 + i;
        }

        return n;
    }

    /// <summary>UTC creation time decoded from the shortcode, or null when it is out of range.</summary>
    public static DateTimeOffset? CreatedAt(string code)
    {
        var ms = (ToPk(code) >> 23) + EpochMs;
        var min = DateTimeOffset.MinValue.ToUnixTimeMilliseconds();
        var max = DateTimeOffset.MaxValue.ToUnixTimeMilliseconds();
        if (ms < min || ms > max)
        {
            return null;
        }

        return DateTimeOffset.FromUnixTimeMilliseconds((long)ms);
    }
}
