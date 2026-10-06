using System.Net;
using System.Text.RegularExpressions;

namespace ThreadsMonitor.Core;

public static class FetchStatus
{
    public const string Ok = "ok";
    public const string Gone = "gone";
    public const string Blocked = "blocked";
    public const string ParseFailed = "parse_failed";
    public const string NetworkError = "network_error";
    public const string HttpError = "http_error";
    public const string Exception = "exception";
    public const string NoPublishTime = "no_publish_time";
}

public sealed record FetchResult(string Status, int? HttpCode, PostData? Data, string? Error, string? Excerpt = null)
{
    public bool IsOk => Status == FetchStatus.Ok && Data != null;

    public static FetchResult Success(int code, PostData data) => new(FetchStatus.Ok, code, data, null);

    public static FetchResult Failure(string status, int? code, string error, string? excerpt = null) =>
        new(status, code, null, error, excerpt);
}

/// <summary>
/// HTTP clients used for Threads requests. Pages follow redirects; share links do not.
/// No cookies are kept.
/// </summary>
public sealed class ThreadsHttp : IDisposable
{
    public HttpClient Page { get; }
    public HttpClient NoRedirect { get; }
    public string UserAgent { get; }

    public ThreadsHttp(HttpClient page, HttpClient noRedirect, string userAgent)
    {
        Page = page;
        NoRedirect = noRedirect;
        UserAgent = userAgent;
    }

    public static ThreadsHttp CreateDefault(string userAgent, TimeSpan timeout)
    {
        var page = new HttpClient(new SocketsHttpHandler { AllowAutoRedirect = true, UseCookies = false, AutomaticDecompression = DecompressionMethods.All }) { Timeout = timeout };
        var noRedirect = new HttpClient(new SocketsHttpHandler { AllowAutoRedirect = false, UseCookies = false, AutomaticDecompression = DecompressionMethods.All }) { Timeout = timeout };
        return new ThreadsHttp(page, noRedirect, userAgent);
    }

    /// <summary>For tests: one handler serves both clients.</summary>
    public static ThreadsHttp FromHandler(HttpMessageHandler handler, string userAgent, TimeSpan timeout) =>
        new(new HttpClient(handler, disposeHandler: false) { Timeout = timeout },
            new HttpClient(handler, disposeHandler: false) { Timeout = timeout },
            userAgent);

    public HttpRequestMessage Get(string url)
    {
        var req = new HttpRequestMessage(HttpMethod.Get, url);
        req.Headers.TryAddWithoutValidation("User-Agent", UserAgent);
        req.Headers.TryAddWithoutValidation("Accept-Language", "en-US,en;q=0.9");
        return req;
    }

    public void Dispose()
    {
        Page.Dispose();
        NoRedirect.Dispose();
    }
}

/// <summary>
/// Fetches one logged-out public post page and classifies the outcome.
///
///  - 404 / 410                                   -> gone
///  - 429, or a 200 page with neither post JSON
///    nor og:description (login wall / challenge) -> blocked
///  - 200 with og:description but no post JSON   -> parse_failed (except the gone heuristic)
///  - connection failure / timeout                -> network_error
///  - anything else                               -> http_error
/// </summary>
public sealed class PublicPageFetcher(ThreadsHttp http, PostPageParser parser)
{
    private const int ExcerptLimit = 500;

    public async Task<FetchResult> FetchAsync(string shortcode, CancellationToken ct = default)
    {
        if (!Shortcode.IsValid(shortcode))
        {
            return FetchResult.Failure(FetchStatus.HttpError, null, "Invalid shortcode.");
        }

        int code;
        string body;
        try
        {
            using var req = http.Get(ThreadsUrl.Canonical(shortcode));
            using var resp = await http.Page.SendAsync(req, ct).ConfigureAwait(false);
            code = (int)resp.StatusCode;
            body = await resp.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        }
        catch (HttpRequestException e)
        {
            return FetchResult.Failure(FetchStatus.NetworkError, null, Describe(e));
        }
        catch (TaskCanceledException) when (!ct.IsCancellationRequested)
        {
            return FetchResult.Failure(FetchStatus.NetworkError, null, "Connection failed: request timed out.");
        }
        catch (Exception e) when (e is not OperationCanceledException)
        {
            return FetchResult.Failure(FetchStatus.HttpError, null, Text.OneLine(e.GetType().Name + ": " + e.Message));
        }

        if (code is 404 or 410)
        {
            return FetchResult.Failure(FetchStatus.Gone, code, $"HTTP {code}: post not found.", Excerpt(body));
        }
        if (code == 429)
        {
            return FetchResult.Failure(FetchStatus.Blocked, code, "HTTP 429: rate limited.", Excerpt(body));
        }
        if (code != 200)
        {
            return FetchResult.Failure(FetchStatus.HttpError, code, $"Unexpected HTTP {code}.", Excerpt(body));
        }

        try
        {
            return FetchResult.Success(code, parser.Parse(body, shortcode));
        }
        catch (ParseException)
        {
            return ClassifyMissingPost(body, shortcode, code);
        }
    }

    /// <summary>
    /// A connection failure without its message: the message can contain the requested URL or a proxy
    /// address with credentials. Only the exception type, the HTTP status and the socket error are kept.
    /// </summary>
    public static string Describe(HttpRequestException e)
    {
        var parts = new List<string> { e.GetType().Name };
        if (e.StatusCode is { } status)
        {
            parts.Add("HTTP " + ((int)status).ToString(System.Globalization.CultureInfo.InvariantCulture));
        }
        for (var inner = e.InnerException; inner != null; inner = inner.InnerException)
        {
            if (inner is System.Net.Sockets.SocketException se)
            {
                parts.Add("SocketError=" + se.SocketErrorCode);
                break;
            }
        }

        return "Connection failed: " + string.Join(", ", parts) + ".";
    }

    private FetchResult ClassifyMissingPost(string body, string shortcode, int code)
    {
        if (parser.OgContent(body, "description") == null)
        {
            return FetchResult.Failure(FetchStatus.Blocked, code,
                "No post data and no og:description (login wall or challenge page).", Excerpt(body));
        }

        // Unverified heuristic: a deleted post is assumed to be served as a generic page whose og:url
        // no longer points at this post. Only that combination is treated as gone; everything else
        // stays parse_failed so a parser break is not mistaken for a deletion.
        var ogUrl = parser.OgContent(body, "url");
        if (ogUrl != null && !Regex.IsMatch(ogUrl, "/(?:post|t)/" + Regex.Escape(shortcode) + @"(?:[/?#]|$)"))
        {
            return FetchResult.Failure(FetchStatus.Gone, code,
                "Page does not reference this post (assumed deleted, unverified heuristic).", Excerpt(body));
        }

        return FetchResult.Failure(FetchStatus.ParseFailed, code, "Page loaded but post object not found in page JSON.", Excerpt(body));
    }

    /// <summary>Short, single-line excerpt for the log: title + og tags, never the full page.</summary>
    private string? Excerpt(string body)
    {
        if (body.Length == 0)
        {
            return null;
        }

        var parts = new List<string>();
        var t = Regex.Match(body, "<title[^>]*>(.*?)</title>", RegexOptions.IgnoreCase | RegexOptions.Singleline);
        if (t.Success)
        {
            parts.Add("title=" + WebUtility.HtmlDecode(t.Groups[1].Value.Trim()));
        }
        foreach (var p in new[] { "url", "title" })
        {
            if (parser.OgContent(body, p) is { } v)
            {
                parts.Add($"og:{p}={v}");
            }
        }
        parts.Add("bytes=" + System.Text.Encoding.UTF8.GetByteCount(body));

        return Text.Limit(Text.OneLine(string.Join(" | ", parts)), ExcerptLimit);
    }
}

public sealed class ShareResolveException(string message, bool isNetwork = false) : Exception(message)
{
    /// <summary>True when Threads could not be reached at all (this computer is probably offline).</summary>
    public bool IsNetwork { get; } = isNetwork;
}

/// <summary>
/// Turns a Threads share code (from https://www.threads.com/share/{code}/) into a post URL.
///
/// Exactly one GET, no redirects followed, no cookies:
///  - 3xx: the Location header is the post URL.
///  - 200: fall back to og:url, then &lt;link rel="canonical"&gt; (unverified path).
/// Whatever comes back must pass ThreadsUrl.Parse(), otherwise ShareResolveException.
/// </summary>
public sealed class ShareUrlResolver(ThreadsHttp http)
{
    private const string Base = "https://www.threads.com";

    private static readonly Regex[] PagePatterns =
    [
        new("<meta\\s[^>]*property=[\"']og:url[\"'][^>]*content=[\"']([^\"']+)[\"']", RegexOptions.IgnoreCase),
        new("<meta\\s[^>]*content=[\"']([^\"']+)[\"'][^>]*property=[\"']og:url[\"']", RegexOptions.IgnoreCase),
        new("<link\\s[^>]*rel=[\"']canonical[\"'][^>]*href=[\"']([^\"']+)[\"']", RegexOptions.IgnoreCase),
        new("<link\\s[^>]*href=[\"']([^\"']+)[\"'][^>]*rel=[\"']canonical[\"']", RegexOptions.IgnoreCase),
    ];

    public async Task<string> ResolveAsync(string shareCode, CancellationToken ct = default)
    {
        if (!Regex.IsMatch(shareCode, "^" + ThreadsUrl.ShareCodePattern + @"\z"))
        {
            throw new ShareResolveException(Text.T.ShareBadFormat);
        }

        int status;
        string? candidate;
        try
        {
            using var req = http.Get(Base + "/share/" + shareCode + "/");
            using var resp = await http.NoRedirect.SendAsync(req, ct).ConfigureAwait(false);
            status = (int)resp.StatusCode;
            if (status is >= 300 and < 400)
            {
                candidate = resp.Headers.Location?.OriginalString;
            }
            else if (status == 200)
            {
                candidate = PageUrl(await resp.Content.ReadAsStringAsync(ct).ConfigureAwait(false));
            }
            else
            {
                candidate = null;
            }
        }
        catch (HttpRequestException)
        {
            // Never surface the exception text: it can echo the requested URL.
            throw new ShareResolveException(Text.T.ShareNetwork, isNetwork: true);
        }
        catch (TaskCanceledException) when (!ct.IsCancellationRequested)
        {
            throw new ShareResolveException(Text.T.ShareNetwork, isNetwork: true);
        }
        catch (Exception e) when (e is not OperationCanceledException)
        {
            throw new ShareResolveException(Text.T.ShareFailed);
        }

        if (status is 404 or 410)
        {
            throw new ShareResolveException(Text.T.ShareGone);
        }
        if (!(status is >= 300 and < 400) && status != 200)
        {
            throw new ShareResolveException(Text.T.ShareFailed);
        }

        candidate = (candidate ?? "").Trim();
        if (candidate.StartsWith('/') && !candidate.StartsWith("//", StringComparison.Ordinal))
        {
            candidate = Base + candidate;
        }

        if (candidate.Length == 0 || ThreadsUrl.Parse(candidate) == null)
        {
            throw new ShareResolveException(Text.T.ShareFailed);
        }

        return candidate;
    }

    private static string? PageUrl(string html)
    {
        foreach (var re in PagePatterns)
        {
            var m = re.Match(html);
            if (m.Success)
            {
                var url = WebUtility.HtmlDecode(m.Groups[1].Value);
                if (ThreadsUrl.Parse(url) != null)
                {
                    return url;
                }
            }
        }

        return null;
    }
}
