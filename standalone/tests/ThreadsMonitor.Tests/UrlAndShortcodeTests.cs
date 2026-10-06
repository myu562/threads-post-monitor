using System.Numerics;
using ThreadsMonitor.Core;

[assembly: CollectionBehavior(DisableTestParallelization = true)]

namespace ThreadsMonitor.Tests;

public class ThreadsUrlTests
{
    [Theory]
    [InlineData("https://www.threads.net/@example_user/post/DFakeP0st01", "DFakeP0st01", "example_user")]
    [InlineData("https://www.threads.com/@example.user/post/DFakeP0st01", "DFakeP0st01", "example.user")]
    [InlineData("https://threads.com/@example_user/post/DFakeP0st01", "DFakeP0st01", "example_user")]
    [InlineData("https://threads.com/t/DFakeP0st01", "DFakeP0st01", null)]
    [InlineData("https://www.threads.net/t/DFake-_st01", "DFake-_st01", null)]
    [InlineData("https://www.threads.com/@example_user/post/DFakeP0st01?xmt=AQF0abc&igshid=xyz", "DFakeP0st01", "example_user")]
    [InlineData("https://www.threads.com/@example_user/post/DFakeP0st01/", "DFakeP0st01", "example_user")]
    [InlineData("https://threads.com/t/DFakeP0st01/?xmt=1", "DFakeP0st01", null)]
    [InlineData("https://threads.com/t/DFakeP0st01#top", "DFakeP0st01", null)]
    [InlineData("www.threads.com/@example_user/post/DFakeP0st01", "DFakeP0st01", "example_user")]
    [InlineData("  http://threads.net/t/DFakeP0st01\n", "DFakeP0st01", null)]
    [InlineData("https://WWW.THREADS.COM/t/DFakeP0st01", "DFakeP0st01", null)]
    public void Parses_valid_urls(string url, string code, string? user)
    {
        var r = ThreadsUrl.Parse(url);
        Assert.NotNull(r);
        Assert.Equal(code, r.Shortcode);
        Assert.Equal(user, r.Username);
        Assert.Equal("https://www.threads.com/t/" + code, r.CanonicalUrl);
    }

    [Theory]
    [InlineData("")]
    [InlineData("hello world")]
    [InlineData("https://www.instagram.com/p/DFakeP0st01/")]
    [InlineData("https://threads.com.evil.example/t/DFakeP0st01")]
    [InlineData("https://evil.threads.com/t/DFakeP0st01")]
    [InlineData("https://www.threads.com/@example_user")]
    [InlineData("https://www.threads.com/@example_user/post/")]
    [InlineData("https://www.threads.com/t/DFake%20st01")]
    [InlineData("https://www.threads.com/@example_user/post/DFakeP0st01/media")]
    [InlineData("ftp://threads.com/t/DFakeP0st01")]
    [InlineData("javascript:alert(1)//threads.com/t/DFakeP0st01")]
    [InlineData("https://user@threads.com/t/DFakeP0st01")]
    [InlineData("https://threads.com:8443/t/DFakeP0st01")]
    [InlineData("https://threads.com/t/abc")]
    public void Rejects_invalid_urls(string url) => Assert.Null(ThreadsUrl.Parse(url));

    [Theory]
    [InlineData("https://www.threads.com/share/ShareFake01/", "ShareFake01")]
    [InlineData("https://threads.com/share/ShareFake01", "ShareFake01")]
    [InlineData("https://www.threads.net/share/Share-Fake_01/", "Share-Fake_01")]
    [InlineData("https://www.threads.com/share/ShareFake01/?xmt=FAKE&slof=1", "ShareFake01")]
    [InlineData("http://threads.net/share/ShareFake01", "ShareFake01")]
    public void Parse_share_extracts_code(string url, string code)
    {
        Assert.Equal(code, ThreadsUrl.ParseShare(url));
        Assert.Null(ThreadsUrl.Parse(url)); // Parse() keeps rejecting share links
    }

    [Theory]
    [InlineData("https://www.threads.com/@example_user/post/DFakeP0st01")]
    [InlineData("https://evil.example/share/ShareFake01/")]
    [InlineData("https://evil.threads.com/share/ShareFake01/")]
    [InlineData("https://www.threads.com/share/abc/")]
    [InlineData("https://www.threads.com/share/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/")]
    [InlineData("https://www.threads.com/share/Share%20Fake/")]
    [InlineData("https://www.threads.com/share/ShareFake01/more")]
    [InlineData("https://www.threads.com/share/")]
    [InlineData("ftp://threads.com/share/ShareFake01")]
    [InlineData("https://threads.com:8443/share/ShareFake01")]
    public void Parse_share_rejects(string url) => Assert.Null(ThreadsUrl.ParseShare(url));

    [Fact]
    public void Clean_url()
    {
        Assert.Equal("https://www.threads.com/@example_user/post/DFakeP0st01", ThreadsUrl.Clean("DFakeP0st01", "example_user"));
        Assert.Equal("https://www.threads.com/t/DFakeP0st01", ThreadsUrl.Clean("DFakeP0st01", null));
    }
}

public class ShortcodeTests
{
    // Synthetic shortcodes; pk and time were computed independently and agree with each other.
    public static TheoryData<string, string, string> Pairs => new()
    {
        { "DFakeP0st01", "3556315264011394357", "2025-01-29T15:50:37.762Z" },
        { "DFakeP0st02", "3556315264011394358", "2025-01-29T15:50:37.762Z" },
        { "CFake_Old01", "2403393810304720181", "2020-09-21T22:21:30.358Z" },
        { "DeFake-Now1", "4000720700355021877", "2026-10-04T19:44:54.101Z" },
        { "DFakeShr0x9", "3556315266913619069", "2025-01-29T15:50:38.108Z" },
    };

    [Theory]
    [MemberData(nameof(Pairs))]
    public void Decodes_pk(string code, string pk, string _)
    {
        Assert.Equal(BigInteger.Parse(pk), Shortcode.ToPk(code));
    }

    [Theory]
    [MemberData(nameof(Pairs))]
    public void Created_at_is_utc_with_milliseconds(string code, string _, string utc)
    {
        var at = Shortcode.CreatedAt(code);
        Assert.NotNull(at);
        Assert.Equal(TimeSpan.Zero, at.Value.Offset);
        Assert.Equal(utc, at.Value.UtcDateTime.ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'"));
    }

    [Fact]
    public void Rejects_invalid_characters() => Assert.Throws<ArgumentException>(() => Shortcode.ToPk("abc$def"));

    [Fact]
    public void Rejects_empty() => Assert.Throws<ArgumentException>(() => Shortcode.ToPk(""));

    [Fact]
    public void Is_valid()
    {
        Assert.True(Shortcode.IsValid("DFakeP0st01"));
        Assert.False(Shortcode.IsValid("abc"));
        Assert.False(Shortcode.IsValid("abc/def12"));
    }

    [Fact]
    public void Absurdly_long_code_has_no_creation_time() =>
        Assert.Null(Shortcode.CreatedAt(new string('_', 64)));
}
