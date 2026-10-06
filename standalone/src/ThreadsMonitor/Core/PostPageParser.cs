using System.Globalization;
using System.Net;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace ThreadsMonitor.Core;

/// <summary>
/// Metrics parsed from one public post page. Every count is nullable:
/// null means "not available", never "zero".
/// </summary>
public sealed record PostData(
    string Shortcode,
    string? Pk,
    string? Username,
    DateTimeOffset? TakenAt,
    long? Views,
    long? Likes,
    long? Replies,
    long? Reposts,
    long? Quotes,
    long? Shares,
    bool CountsHidden,
    string? Caption = null);

public sealed class ParseException(string reason, string message) : Exception(message)
{
    public const string PostNotFound = "post_not_found";
    public const string EmptyBody = "empty_body";

    public string Reason { get; } = reason;
}

/// <summary>
/// Extracts post metrics from a logged-out Threads post page (server-rendered HTML).
///
///  - post object: inside &lt;script type="application/json"&gt; payloads, located by a recursive
///    search for the object with code == shortcode AND a taken_at key (no hard-coded path).
///  - likes   = media.like_count
///  - replies/reposts/quotes/shares = text_post_app_info.{direct_reply_count, repost_count,
///    quote_count, reshare_count}; reshare_count is often null and must stay null.
///  - views   = page-level config "BarcelonaLoggedOutExpansionGating" -> view_counts.
///  - like_and_view_counts_disabled = author hid counts -> likes/views reported as null.
///  - caption = post object's caption.text (null when caption is null or has no text string).
/// </summary>
public sealed class PostPageParser
{
    private static readonly Regex ScriptRe = new(
        "<script type=\"application/json\"[^>]*>(.*?)</script>",
        RegexOptions.Singleline | RegexOptions.CultureInvariant);

    private static readonly Regex ViewsRe = new(
        "\"BarcelonaLoggedOutExpansionGating\",\\[\\],\\{[^{}]*?\"view_counts\":([0-9]+)",
        RegexOptions.CultureInvariant);

    private const int MaxDepth = 64;

    private static readonly JsonDocumentOptions JsonOptions = new() { MaxDepth = 512 };

    public PostData Parse(string html, string shortcode)
    {
        if (string.IsNullOrWhiteSpace(html))
        {
            throw new ParseException(ParseException.EmptyBody, "Empty response body.");
        }

        return FindPost(html, shortcode, post =>
        {
            var tpai = post.TryGetProperty("text_post_app_info", out var t) && t.ValueKind == JsonValueKind.Object
                ? t
                : (JsonElement?)null;
            var hidden = post.TryGetProperty("like_and_view_counts_disabled", out var h) && h.ValueKind == JsonValueKind.True;

            var takenAt = IntOrNull(Prop(post, "taken_at"));
            string? pk = null;
            if (post.TryGetProperty("pk", out var pkEl))
            {
                if (pkEl.ValueKind == JsonValueKind.String && pkEl.GetString() is { Length: > 0 } s)
                {
                    pk = s;
                }
                else if (pkEl.ValueKind == JsonValueKind.Number && pkEl.TryGetInt64(out var pkNum))
                {
                    pk = pkNum.ToString(CultureInfo.InvariantCulture);
                }
            }

            string? username = null;
            if (post.TryGetProperty("user", out var user) && user.ValueKind == JsonValueKind.Object
                && user.TryGetProperty("username", out var un) && un.ValueKind == JsonValueKind.String
                && un.GetString() is { Length: > 0 } u)
            {
                username = u;
            }

            return new PostData(
                Shortcode: shortcode,
                Pk: pk,
                Username: username,
                TakenAt: takenAt.HasValue ? DateTimeOffset.FromUnixTimeSeconds(takenAt.Value) : null,
                Views: hidden ? null : ExtractViews(html),
                Likes: hidden ? null : IntOrNull(Prop(post, "like_count")),
                Replies: IntOrNull(Prop(tpai, "direct_reply_count")),
                Reposts: IntOrNull(Prop(tpai, "repost_count")),
                Quotes: IntOrNull(Prop(tpai, "quote_count")),
                Shares: IntOrNull(Prop(tpai, "reshare_count")),
                CountsHidden: hidden,
                Caption: CaptionText(post));
        }) ?? throw new ParseException(ParseException.PostNotFound, "Post object for shortcode not found in page JSON.");
    }

    private static T? FindPost<T>(string html, string shortcode, Func<JsonElement, T> map)
    {
        var needle = "\"" + shortcode + "\"";
        foreach (Match m in ScriptRe.Matches(html))
        {
            var json = m.Groups[1].Value;
            if (!json.Contains(needle, StringComparison.Ordinal))
            {
                continue;
            }

            JsonDocument doc;
            try
            {
                doc = JsonDocument.Parse(json, JsonOptions);
            }
            catch (JsonException)
            {
                continue;
            }

            using (doc)
            {
                if (doc.RootElement.ValueKind is not (JsonValueKind.Object or JsonValueKind.Array))
                {
                    continue;
                }
                var hit = Search(doc.RootElement, shortcode, 0);
                if (hit.HasValue)
                {
                    return map(hit.Value);
                }
            }
        }

        return default;
    }

    public long? ExtractViews(string html)
    {
        var m = ViewsRe.Match(html);
        return m.Success ? IntOrNullFromString(m.Groups[1].Value) : null;
    }

    public string? OgContent(string html, string property)
    {
        var re = new Regex("<meta\\s+property=\"og:" + Regex.Escape(property) + "\"\\s+content=\"([^\"]*)\"",
            RegexOptions.CultureInvariant);
        var m = re.Match(html);
        return m.Success ? WebUtility.HtmlDecode(m.Groups[1].Value) : null;
    }

    private static JsonElement? Search(JsonElement node, string code, int depth)
    {
        if (depth > MaxDepth)
        {
            return null;
        }

        if (node.ValueKind == JsonValueKind.Object)
        {
            if (node.TryGetProperty("code", out var c) && c.ValueKind == JsonValueKind.String
                && c.GetString() == code && node.TryGetProperty("taken_at", out _))
            {
                return node;
            }
            foreach (var p in node.EnumerateObject())
            {
                if (p.Value.ValueKind is JsonValueKind.Object or JsonValueKind.Array
                    && Search(p.Value, code, depth + 1) is { } hit)
                {
                    return hit;
                }
            }
        }
        else if (node.ValueKind == JsonValueKind.Array)
        {
            foreach (var child in node.EnumerateArray())
            {
                if (child.ValueKind is JsonValueKind.Object or JsonValueKind.Array
                    && Search(child, code, depth + 1) is { } hit)
                {
                    return hit;
                }
            }
        }

        return null;
    }

    private static string? CaptionText(JsonElement post) =>
        Prop(Prop(post, "caption"), "text") is { ValueKind: JsonValueKind.String } t && t.GetString() is { Length: > 0 } text ? text : null;

    private static JsonElement? Prop(JsonElement? obj, string name) =>
        obj is { ValueKind: JsonValueKind.Object } o && o.TryGetProperty(name, out var v) ? v : null;

    /// <summary>Non-negative integer, or a digit string of at most 18 chars; anything else is null.</summary>
    private static long? IntOrNull(JsonElement? v)
    {
        if (v is not { } e)
        {
            return null;
        }
        if (e.ValueKind == JsonValueKind.Number)
        {
            return e.TryGetInt64(out var n) && n >= 0 ? n : null;
        }
        if (e.ValueKind == JsonValueKind.String)
        {
            return IntOrNullFromString(e.GetString());
        }

        return null;
    }

    private static long? IntOrNullFromString(string? s)
    {
        if (string.IsNullOrEmpty(s) || s.Length > 18 || !s.All(char.IsAsciiDigit))
        {
            return null;
        }

        return long.Parse(s, NumberStyles.None, CultureInfo.InvariantCulture);
    }
}
