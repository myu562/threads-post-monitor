<?php

namespace Tests\Unit\Threads;

use App\Threads\ThreadsUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThreadsUrlTest extends TestCase
{
    public static function validUrls(): array
    {
        return [
            'threads.net post' => ['https://www.threads.net/@example_user/post/DFakeP0st01', 'DFakeP0st01', 'example_user'],
            'threads.com post' => ['https://www.threads.com/@example.user/post/DFakeP0st01', 'DFakeP0st01', 'example.user'],
            'no www' => ['https://threads.com/@example_user/post/DFakeP0st01', 'DFakeP0st01', 'example_user'],
            'short /t/' => ['https://threads.com/t/DFakeP0st01', 'DFakeP0st01', null],
            'short /t/ www net' => ['https://www.threads.net/t/DFake-_st01', 'DFake-_st01', null],
            'query string' => ['https://www.threads.com/@example_user/post/DFakeP0st01?xmt=FAKEXMTAQF&igshid=xyz', 'DFakeP0st01', 'example_user'],
            'trailing slash' => ['https://www.threads.com/@example_user/post/DFakeP0st01/', 'DFakeP0st01', 'example_user'],
            'trailing slash + query' => ['https://threads.com/t/DFakeP0st01/?xmt=FAKE1', 'DFakeP0st01', null],
            'fragment' => ['https://threads.com/t/DFakeP0st01#top', 'DFakeP0st01', null],
            'no scheme' => ['www.threads.com/@example_user/post/DFakeP0st01', 'DFakeP0st01', 'example_user'],
            'http + whitespace' => ["  http://threads.net/t/DFakeP0st01\n", 'DFakeP0st01', null],
            'uppercase host' => ['https://WWW.THREADS.COM/t/DFakeP0st01', 'DFakeP0st01', null],
        ];
    }

    #[DataProvider('validUrls')]
    public function test_parses_valid_urls(string $url, string $code, ?string $user): void
    {
        $r = ThreadsUrl::parse($url);
        $this->assertNotNull($r);
        $this->assertSame($code, $r['shortcode']);
        $this->assertSame($user, $r['username']);
        $this->assertSame('https://www.threads.com/t/'.$code, $r['canonical_url']);
    }

    public static function invalidUrls(): array
    {
        return [
            'empty' => [''],
            'not a url' => ['hello world'],
            'other host' => ['https://www.instagram.com/p/DFakeP0st01/'],
            'lookalike host' => ['https://threads.com.evil.example/t/DFakeP0st01'],
            'subdomain' => ['https://evil.threads.com/t/DFakeP0st01'],
            'profile only' => ['https://www.threads.com/@example_user'],
            'missing code' => ['https://www.threads.com/@example_user/post/'],
            'bad chars in code' => ['https://www.threads.com/t/DFake%20st01'],
            'extra path' => ['https://www.threads.com/@example_user/post/DFakeP0st01/media'],
            'ftp scheme' => ['ftp://threads.com/t/DFakeP0st01'],
            'javascript' => ['javascript:alert(1)//threads.com/t/DFakeP0st01'],
            'userinfo' => ['https://user@threads.com/t/DFakeP0st01'],
            'port' => ['https://threads.com:8443/t/DFakeP0st01'],
            'too short code' => ['https://threads.com/t/abc'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_rejects_invalid_urls(string $url): void
    {
        $this->assertNull(ThreadsUrl::parse($url));
    }

    public static function shareUrls(): array
    {
        return [
            'com www trailing slash' => ['https://www.threads.com/share/ShareFake01/', 'ShareFake01'],
            'com no slash' => ['https://threads.com/share/ShareFake01', 'ShareFake01'],
            'net' => ['https://www.threads.net/share/Share-Fake_01/', 'Share-Fake_01'],
            'query' => ['https://www.threads.com/share/ShareFake01/?xmt=FAKE&slof=1', 'ShareFake01'],
            'http' => ['http://threads.net/share/ShareFake01', 'ShareFake01'],
        ];
    }

    #[DataProvider('shareUrls')]
    public function test_parse_share_extracts_code(string $url, string $code): void
    {
        $this->assertSame($code, ThreadsUrl::parseShare($url));
        $this->assertNull(ThreadsUrl::parse($url), 'parse() must keep rejecting share links');
    }

    public static function notShareUrls(): array
    {
        return [
            'post url' => ['https://www.threads.com/@example_user/post/DFakeP0st01'],
            'other host' => ['https://evil.example/share/ShareFake01/'],
            'subdomain' => ['https://evil.threads.com/share/ShareFake01/'],
            'too short' => ['https://www.threads.com/share/abc/'],
            'too long' => ['https://www.threads.com/share/'.str_repeat('a', 65).'/'],
            'bad chars' => ['https://www.threads.com/share/Share%20Fake/'],
            'extra path' => ['https://www.threads.com/share/ShareFake01/more'],
            'empty code' => ['https://www.threads.com/share/'],
            'ftp' => ['ftp://threads.com/share/ShareFake01'],
            'port' => ['https://threads.com:8443/share/ShareFake01'],
        ];
    }

    #[DataProvider('notShareUrls')]
    public function test_parse_share_rejects(string $url): void
    {
        $this->assertNull(ThreadsUrl::parseShare($url));
    }

    public function test_clean_url(): void
    {
        $this->assertSame('https://www.threads.com/@example_user/post/DFakeP0st01', ThreadsUrl::clean('DFakeP0st01', 'example_user'));
        $this->assertSame('https://www.threads.com/t/DFakeP0st01', ThreadsUrl::clean('DFakeP0st01', null));
    }
}
