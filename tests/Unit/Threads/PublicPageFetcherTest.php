<?php

namespace Tests\Unit\Threads;

use App\Threads\FetchResult;
use App\Threads\PublicPageFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicPageFetcherTest extends TestCase
{
    private const CODE = 'DFakeP0st01';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/'.$name);
    }

    private function fetch(): FetchResult
    {
        return $this->app->make(PublicPageFetcher::class)->fetch(self::CODE);
    }

    public function test_200_with_post_is_ok_and_sends_expected_request(): void
    {
        config(['threads.user_agent' => 'TestAgent/1.0']);
        Http::fake(['https://www.threads.com/t/'.self::CODE => Http::response($this->fixture('post_ok.html'), 200)]);

        $r = $this->fetch();

        $this->assertTrue($r->ok);
        $this->assertSame('ok', $r->status);
        $this->assertSame(200, $r->httpCode);
        $this->assertSame(13410, $r->data->views);
        $this->assertNull($r->error);
        $this->assertNotNull($r->rawExcerpt);

        Http::assertSent(fn (Request $req) => $req->url() === 'https://www.threads.com/t/'.self::CODE
            && $req->hasHeader('User-Agent', 'TestAgent/1.0')
            && $req->hasHeader('Accept-Language', 'en-US,en;q=0.9')
            && $req->method() === 'GET');
        Http::assertSentCount(1);
    }

    public function test_404_is_gone(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $r = $this->fetch();
        $this->assertFalse($r->ok);
        $this->assertSame('gone', $r->status);
        $this->assertSame(404, $r->httpCode);
        $this->assertNull($r->data);
    }

    public function test_410_is_gone(): void
    {
        Http::fake(['*' => Http::response('', 410)]);
        $this->assertSame('gone', $this->fetch()->status);
    }

    public function test_429_is_blocked(): void
    {
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);
        $r = $this->fetch();
        $this->assertSame('blocked', $r->status);
        $this->assertSame(429, $r->httpCode);
    }

    public function test_login_wall_page_is_blocked(): void
    {
        Http::fake(['*' => Http::response($this->fixture('login_wall.html'), 200)]);
        $r = $this->fetch();
        $this->assertSame('blocked', $r->status);
        $this->assertSame(200, $r->httpCode);
        $this->assertStringContainsString('title=Log in', $r->rawExcerpt);
    }

    public function test_unexpected_html_with_og_for_this_post_is_parse_failed(): void
    {
        Http::fake(['*' => Http::response($this->fixture('page_without_post.html'), 200)]);
        $r = $this->fetch();
        $this->assertSame('parse_failed', $r->status);
        $this->assertFalse($r->ok);
    }

    public function test_generic_page_not_referencing_post_is_gone_heuristic(): void
    {
        Http::fake(['*' => Http::response($this->fixture('page_gone.html'), 200)]);
        $this->assertSame('gone', $this->fetch()->status);
    }

    public function test_server_error_is_http_error(): void
    {
        Http::fake(['*' => Http::response('oops', 503)]);
        $r = $this->fetch();
        $this->assertSame('http_error', $r->status);
        $this->assertSame(503, $r->httpCode);
    }

    public function test_connection_exception_is_network_error_with_one_line_message(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException("cURL error 28: timed out\nsecond line")]);
        $r = $this->fetch();
        $this->assertSame('network_error', $r->status);
        $this->assertNull($r->httpCode);
        $this->assertStringNotContainsString("\n", $r->error);
        $this->assertStringContainsString('timed out', $r->error);
    }

    public function test_invalid_shortcode_makes_no_request(): void
    {
        Http::fake();
        $r = $this->app->make(PublicPageFetcher::class)->fetch('../x');
        $this->assertFalse($r->ok);
        Http::assertNothingSent();
    }
}
