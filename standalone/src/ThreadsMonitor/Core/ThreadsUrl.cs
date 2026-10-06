using System.Text.RegularExpressions;

namespace ThreadsMonitor.Core;

public sealed record ParsedPostUrl(string Shortcode, string? Username, string CanonicalUrl);

/// <summary>
/// Parses user-supplied Threads post URLs into a shortcode.
///
/// Accepted:
///   https://www.threads.net/@{username}/post/{shortcode}
///   https://www.threads.com/@{username}/post/{shortcode}
///   https://threads.com/t/{shortcode}
/// with or without "www.", an optional query string / fragment and a trailing slash.
/// The scheme may be omitted. Anything else returns null.
///
/// Share links (https://www.threads.com/share/{code}/) are not post URLs: Parse() returns null
/// for them, ParseShare() extracts the code and ShareUrlResolver turns it into a post URL.
/// </summary>
public static class ThreadsUrl
{
    private static readonly string[] Hosts = ["threads.net", "www.threads.net", "threads.com", "www.threads.com"];

    public const string ShareCodePattern = "[A-Za-z0-9_-]{4,64}";

    private static readonly Regex SchemeRe = new(@"^[a-z][a-z0-9+.-]*://", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
    private static readonly Regex PostPathRe = new(@"^/@([A-Za-z0-9_.]{1,64})/post/(" + Core.Shortcode.Pattern + @")\z", RegexOptions.CultureInvariant);
    private static readonly Regex ShortPathRe = new(@"^/t/(" + Core.Shortcode.Pattern + @")\z", RegexOptions.CultureInvariant);
    private static readonly Regex SharePathRe = new(@"^/share/(" + ShareCodePattern + @")\z", RegexOptions.CultureInvariant);

    public static ParsedPostUrl? Parse(string url)
    {
        var path = ThreadsPath(url);
        if (path == null)
        {
            return null;
        }

        path = path.TrimEnd('/');
        string shortcode;
        string? username;
        Match m;
        if ((m = PostPathRe.Match(path)).Success)
        {
            username = m.Groups[1].Value;
            shortcode = m.Groups[2].Value;
        }
        else if ((m = ShortPathRe.Match(path)).Success)
        {
            username = null;
            shortcode = m.Groups[1].Value;
        }
        else
        {
            return null;
        }

        return new ParsedPostUrl(shortcode, username, Canonical(shortcode));
    }

    /// <summary>Share code from http(s)://(www.)threads.(com|net)/share/{code}[/][?query], or null.</summary>
    public static string? ParseShare(string url)
    {
        var path = ThreadsPath(url);
        if (path == null)
        {
            return null;
        }

        var m = SharePathRe.Match(path.TrimEnd('/'));
        return m.Success ? m.Groups[1].Value : null;
    }

    /// <summary>The URL shown for a post: no query, no fragment, no share code.</summary>
    public static string Clean(string shortcode, string? username) =>
        string.IsNullOrEmpty(username)
            ? Canonical(shortcode)
            : "https://www.threads.com/@" + username + "/post/" + shortcode;

    /// <summary>The URL the fetcher requests.</summary>
    public static string Canonical(string shortcode) => "https://www.threads.com/t/" + shortcode;

    /// <summary>
    /// Path of an http(s) URL on a Threads host (no userinfo, no port), else null.
    /// </summary>
    private static string? ThreadsPath(string url)
    {
        url = url.Trim();
        if (url.Length == 0)
        {
            return null;
        }
        if (!SchemeRe.IsMatch(url))
        {
            url = "https://" + url;
        }

        var schemeEnd = url.IndexOf("://", StringComparison.Ordinal);
        var scheme = url[..schemeEnd].ToLowerInvariant();
        if (scheme != "https" && scheme != "http")
        {
            return null;
        }

        var rest = url[(schemeEnd + 3)..];
        var authorityEnd = rest.IndexOfAny(['/', '?', '#']);
        var authority = authorityEnd < 0 ? rest : rest[..authorityEnd];
        var tail = authorityEnd < 0 ? "" : rest[authorityEnd..];

        if (authority.Length == 0 || authority.Contains('@') || authority.Contains(':'))
        {
            return null; // userinfo or port
        }
        if (!Hosts.Contains(authority.ToLowerInvariant()))
        {
            return null;
        }

        var pathEnd = tail.IndexOfAny(['?', '#']);
        return pathEnd < 0 ? tail : tail[..pathEnd];
    }
}
