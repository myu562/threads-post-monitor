using System.Net;
using System.Text.RegularExpressions;
using ThreadsMonitor.Core;

namespace ThreadsMonitor.Tests;

public class PostPageParserTests
{
    private const string Code = Fixtures.Code;

    [Fact]
    public void Parses_all_metrics_from_main_post_not_reply()
    {
        var d = new PostPageParser().Parse(Fixtures.Read("post_ok.html"), Code);

        Assert.Equal(Code, d.Shortcode);
        Assert.Equal(Shortcode.ToPk(Code).ToString(), d.Pk);
        Assert.Equal("example_user", d.Username);
        Assert.Equal(1791000000, d.TakenAt!.Value.ToUnixTimeSeconds());
        Assert.Equal(TimeSpan.Zero, d.TakenAt.Value.Offset);
        Assert.Equal(13410, d.Views);
        Assert.Equal(321, d.Likes); // the reply in the same payload has 5
        Assert.Equal(17, d.Replies);
        Assert.Equal(4, d.Reposts);
        Assert.Equal(2, d.Quotes);
        Assert.Equal(9, d.Shares);
        Assert.False(d.CountsHidden);
    }

    [Fact]
    public void Null_shares_stay_null_not_zero()
    {
        var d = new PostPageParser().Parse(Fixtures.Read("post_null_shares.html"), Code);
        Assert.Null(d.Shares);
        Assert.Equal(250, d.Views);
        Assert.Equal(17, d.Replies);
    }

    [Fact]
    public void Counts_hidden_by_author_nulls_likes_and_views()
    {
        var d = new PostPageParser().Parse(Fixtures.Read("post_counts_hidden.html"), Code);
        Assert.True(d.CountsHidden);
        Assert.Null(d.Likes);
        Assert.Null(d.Views);
        Assert.Equal(17, d.Replies);
    }

    [Fact]
    public void Missing_views_config_gives_null_views()
    {
        var d = new PostPageParser().Parse(Fixtures.Read("post_missing_views.html"), Code);
        Assert.Null(d.Views); // decoy "view_counts":999999 in another config must be ignored
        Assert.Equal(321, d.Likes);
    }

    [Fact]
    public void Throws_when_post_object_not_found()
    {
        var e = Assert.Throws<ParseException>(() => new PostPageParser().Parse(Fixtures.Read("page_without_post.html"), Code));
        Assert.Equal(ParseException.PostNotFound, e.Reason);
    }

    [Fact]
    public void Throws_for_other_shortcode() =>
        Assert.Throws<ParseException>(() => new PostPageParser().Parse(Fixtures.Read("post_ok.html"), "DOtherC0de9"));

    [Fact]
    public void Throws_on_empty_body()
    {
        var e = Assert.Throws<ParseException>(() => new PostPageParser().Parse("  \n", Code));
        Assert.Equal(ParseException.EmptyBody, e.Reason);
    }

    [Fact]
    public void Views_regex_reads_gating_config_only()
    {
        var p = new PostPageParser();
        Assert.Equal(6433, p.ExtractViews("x,[\"BarcelonaLoggedOutExpansionGating\",[],{\"enable_nav\":true,\"enable_view_counts\":false,\"view_counts\":6433},123]"));
        Assert.Null(p.ExtractViews("[\"OtherGating\",[],{\"view_counts\":6433}]"));
        Assert.Null(p.ExtractViews("[\"BarcelonaLoggedOutExpansionGating\",[],{\"a\":{\"b\":1},\"view_counts\":5}]"));
        Assert.Null(p.ExtractViews("[\"BarcelonaLoggedOutExpansionGating\",[],{\"view_counts\":null}]"));
    }

    [Fact]
    public void Reads_og_content_decoded()
    {
        var p = new PostPageParser();
        var html = Fixtures.Read("post_ok.html");
        Assert.Equal("https://www.threads.com/@example_user/post/" + Code, p.OgContent(html, "url"));
        Assert.Equal("Example User (@example_user) on Threads", p.OgContent(html, "title"));
        Assert.Null(p.OgContent(Fixtures.Read("login_wall.html"), "description"));
    }
}

public class PublicPageFetcherTests
{
    private const string Code = Fixtures.Code;

    private static (FetchResult Result, FakeHandler Handler) Fetch(FakeHandler h, string code = Code, string ua = "TestAgent/1.0")
    {
        using var http = ThreadsHttp.FromHandler(h, ua, TimeSpan.FromSeconds(30));
        var r = new PublicPageFetcher(http, new PostPageParser()).FetchAsync(code).GetAwaiter().GetResult();
        return (r, h);
    }

    [Fact]
    public void Ok_200_with_post_and_sends_expected_request()
    {
        var (r, h) = Fetch(FakeHandler.Html(Fixtures.Read("post_ok.html")));

        Assert.True(r.IsOk);
        Assert.Equal("ok", r.Status);
        Assert.Equal(200, r.HttpCode);
        Assert.Equal(13410, r.Data!.Views);
        Assert.Null(r.Error);

        var req = Assert.Single(h.Requests);
        Assert.Equal(HttpMethod.Get, req.Method);
        Assert.Equal("https://www.threads.com/t/" + Code, req.RequestUri!.ToString());
        Assert.Equal("TestAgent/1.0", req.Headers.NonValidated["User-Agent"].ToString());
        Assert.Equal("en-US,en;q=0.9", req.Headers.NonValidated["Accept-Language"].ToString());
    }

    [Theory]
    [InlineData(404)]
    [InlineData(410)]
    public void Not_found_is_gone(int code)
    {
        var (r, _) = Fetch(FakeHandler.Status(code, "Not Found"));
        Assert.False(r.IsOk);
        Assert.Equal("gone", r.Status);
        Assert.Equal(code, r.HttpCode);
        Assert.Null(r.Data);
    }

    [Fact]
    public void Too_many_requests_is_blocked()
    {
        var (r, _) = Fetch(FakeHandler.Status(429, "Too Many Requests"));
        Assert.Equal("blocked", r.Status);
        Assert.Equal(429, r.HttpCode);
    }

    [Fact]
    public void Login_wall_page_is_blocked()
    {
        var (r, _) = Fetch(FakeHandler.Html(Fixtures.Read("login_wall.html")));
        Assert.Equal("blocked", r.Status);
        Assert.Equal(200, r.HttpCode);
        Assert.Contains("title=Log in", r.Excerpt);
    }

    [Fact]
    public void Page_for_this_post_without_json_is_parse_failed()
    {
        var (r, _) = Fetch(FakeHandler.Html(Fixtures.Read("page_without_post.html")));
        Assert.Equal("parse_failed", r.Status);
        Assert.False(r.IsOk);
    }

    [Fact]
    public void Generic_page_not_referencing_post_is_gone_heuristic()
    {
        var (r, _) = Fetch(FakeHandler.Html(Fixtures.Read("page_gone.html")));
        Assert.Equal("gone", r.Status);
    }

    [Fact]
    public void Server_error_is_http_error()
    {
        var (r, _) = Fetch(FakeHandler.Status(503, "oops"));
        Assert.Equal("http_error", r.Status);
        Assert.Equal(503, r.HttpCode);
    }

    [Fact]
    public void Connection_failure_is_network_error_with_one_line_message()
    {
        var (r, _) = Fetch(FakeHandler.Throws(new HttpRequestException("timed out\nsecond line",
            new System.Net.Sockets.SocketException((int)System.Net.Sockets.SocketError.HostNotFound))));
        Assert.Equal("network_error", r.Status);
        Assert.Null(r.HttpCode);
        Assert.DoesNotContain("\n", r.Error);
        // Only the type and the socket error are kept: the message may contain URLs or proxy credentials.
        Assert.Equal("Connection failed: HttpRequestException, SocketError=HostNotFound.", r.Error);
    }

    [Fact]
    public void Timeout_is_network_error()
    {
        var (r, _) = Fetch(FakeHandler.Throws(new TaskCanceledException("timeout")));
        Assert.Equal("network_error", r.Status);
    }

    [Fact]
    public void Invalid_shortcode_makes_no_request()
    {
        var (r, h) = Fetch(FakeHandler.Html("x"), "../x");
        Assert.False(r.IsOk);
        Assert.Empty(h.Requests);
    }
}

public class ShareUrlResolverTests
{
    private const string Code = "ShareFake01";

    private static FakeHandler Redirect(int code, string location) =>
        new(_ =>
        {
            var resp = new HttpResponseMessage((HttpStatusCode)code);
            resp.Headers.Location = new Uri(location, UriKind.RelativeOrAbsolute);
            return resp;
        });

    private static string Resolve(FakeHandler h, string code = Code)
    {
        using var http = ThreadsHttp.FromHandler(h, "TestBot/1.0", TimeSpan.FromSeconds(30));
        return new ShareUrlResolver(http).ResolveAsync(code).GetAwaiter().GetResult();
    }

    private static ShareResolveException Failure(FakeHandler h)
    {
        Text.T = Strings.Chinese;
        try
        {
            var e = Assert.Throws<ShareResolveException>(() => Resolve(h));
            Assert.Single(h.Requests);
            Assert.DoesNotContain("\n", e.Message);
            Assert.Matches(new Regex(@"\p{IsCJKUnifiedIdeographs}"), e.Message);
            return e;
        }
        finally
        {
            Text.T = Strings.English;
        }
    }

    [Fact]
    public void Redirect_302_location_with_tracking_query()
    {
        const string location = "https://www.threads.com/@example_user/post/DFakeP0st01?xmt=FAKEXMTVALUE01&slof=1";
        var h = Redirect(302, location);

        Assert.Equal(location, Resolve(h));
        var req = Assert.Single(h.Requests);
        Assert.Equal(HttpMethod.Get, req.Method);
        Assert.Equal("https://www.threads.com/share/ShareFake01/", req.RequestUri!.ToString());
        Assert.Equal("TestBot/1.0", req.Headers.NonValidated["User-Agent"].ToString());
    }

    [Fact]
    public void Relative_location_is_accepted()
    {
        var h = Redirect(301, "/@example_user/post/DFakeP0st01");
        Assert.Equal("https://www.threads.com/@example_user/post/DFakeP0st01", Resolve(h));
        Assert.Single(h.Requests);
    }

    [Fact]
    public void Location_that_is_not_a_post_fails() =>
        Failure(Redirect(302, "https://www.threads.com/login?next=%2Fshare%2FShareFake01"));

    [Fact]
    public void Location_to_other_host_fails() =>
        Failure(Redirect(302, "https://evil.example/@example_user/post/DFakeP0st01"));

    [Fact]
    public void Ok_200_with_og_url()
    {
        var h = FakeHandler.Html(Fixtures.Read("share_og_url.html"));
        Assert.Equal("https://www.threads.com/@example_user/post/DFakeP0st01", Resolve(h));
        Assert.Single(h.Requests);
    }

    [Fact]
    public void Ok_200_with_canonical_link()
    {
        var h = FakeHandler.Html("<html><head><link rel=\"canonical\" href=\"https://www.threads.com/@example_user/post/DFakeP0st01\"></head></html>");
        Assert.Equal("https://www.threads.com/@example_user/post/DFakeP0st01", Resolve(h));
    }

    [Fact]
    public void Ok_200_without_og_fails() =>
        Failure(FakeHandler.Html("<html><head><title>Threads</title></head><body></body></html>"));

    [Fact]
    public void Ok_200_with_og_url_not_a_post_fails() =>
        Failure(FakeHandler.Html("<meta property=\"og:url\" content=\"https://www.threads.com/\">"));

    [Fact]
    public void Not_found_fails_as_expired() =>
        Assert.Contains("失效", Failure(FakeHandler.Status(404)).Message);

    [Fact]
    public void Server_error_fails() => Failure(FakeHandler.Status(500));

    [Fact]
    public void Connection_failure_is_wrapped_without_url()
    {
        var e = Failure(FakeHandler.Throws(new HttpRequestException("error for https://www.threads.com/share/ShareFake01/?xmt=FAKEXMTVALUE01")));
        Assert.DoesNotContain("xmt", e.Message);
        Assert.DoesNotContain(Code, e.Message);
        Assert.DoesNotContain("http", e.Message);
    }

    [Fact]
    public void Invalid_code_makes_no_request()
    {
        var h = FakeHandler.Html("x");
        Assert.Throws<ShareResolveException>(() => Resolve(h, "bad/code"));
        Assert.Empty(h.Requests);
    }
}
