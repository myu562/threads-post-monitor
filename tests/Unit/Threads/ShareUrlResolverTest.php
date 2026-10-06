<?php

namespace Tests\Unit\Threads;

use App\Support\UserMessage;
use App\Threads\Exceptions\ShareResolveException;
use App\Threads\ShareUrlResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShareUrlResolverTest extends TestCase
{
    private const CODE = 'ShareFake01';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['threads.user_agent' => 'TestBot/1.0']);
    }

    private function resolveExpectingFailure(): ShareResolveException
    {
        try {
            app(ShareUrlResolver::class)->resolve(self::CODE);
        } catch (ShareResolveException $e) {
            Http::assertSentCount(1);
            // The message is a translation key (contract L4); both languages have one-line text for it.
            $this->assertMatchesRegularExpression('/^errors\.share\.[a-z_]+$/', $e->getMessage());
            foreach (['zh_TW' => '/\p{Han}/u', 'en' => '/^[\x20-\x7E]+$/'] as $locale => $script) {
                app()->setLocale($locale);
                $text = UserMessage::of($e);
                $this->assertStringNotContainsString("\n", $text);
                $this->assertMatchesRegularExpression($script, $text);
            }

            return $e;
        }
        $this->fail('Expected ShareResolveException');
    }

    public function test_302_location_with_tracking_query(): void
    {
        $location = 'https://www.threads.com/@example_user/post/DFakeP0st01?xmt=FAKEXMTVALUE01&slof=1';
        Http::fake(['*' => Http::response('', 302, ['Location' => $location])]);

        $url = app(ShareUrlResolver::class)->resolve(self::CODE);

        $this->assertSame($location, $url);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://www.threads.com/share/ShareFake01/'
            && $r->header('User-Agent') === ['TestBot/1.0']);
    }

    public function test_relative_location_is_accepted(): void
    {
        Http::fake(['*' => Http::response('', 301, ['Location' => '/@example_user/post/DFakeP0st01'])]);

        $this->assertSame('https://www.threads.com/@example_user/post/DFakeP0st01', app(ShareUrlResolver::class)->resolve(self::CODE));
        Http::assertSentCount(1);
    }

    public function test_location_that_is_not_a_post_fails(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://www.threads.com/login?next=%2Fshare%2FShareFake01'])]);

        $this->resolveExpectingFailure();
    }

    public function test_location_to_other_host_fails(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example/@example_user/post/DFakeP0st01'])]);

        $this->resolveExpectingFailure();
    }

    public function test_200_with_og_url(): void
    {
        Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/share_og_url.html')), 200)]);

        $this->assertSame('https://www.threads.com/@example_user/post/DFakeP0st01', app(ShareUrlResolver::class)->resolve(self::CODE));
        Http::assertSentCount(1);
    }

    public function test_200_with_canonical_link(): void
    {
        Http::fake(['*' => Http::response('<html><head><link rel="canonical" href="https://www.threads.com/@example_user/post/DFakeP0st01"></head></html>', 200)]);

        $this->assertSame('https://www.threads.com/@example_user/post/DFakeP0st01', app(ShareUrlResolver::class)->resolve(self::CODE));
        Http::assertSentCount(1);
    }

    public function test_200_without_og_fails(): void
    {
        Http::fake(['*' => Http::response('<html><head><title>Threads</title></head><body></body></html>', 200)]);

        $this->resolveExpectingFailure();
    }

    public function test_200_with_og_url_not_a_post_fails(): void
    {
        Http::fake(['*' => Http::response('<meta property="og:url" content="https://www.threads.com/">', 200)]);

        $this->resolveExpectingFailure();
    }

    public function test_404_fails(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $e = $this->resolveExpectingFailure();
        $this->assertSame('errors.share.gone', $e->getMessage());
        app()->setLocale('zh_TW');
        $this->assertStringContainsString('失效', UserMessage::of($e));
    }

    public function test_500_fails(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->resolveExpectingFailure();
    }

    public function test_connection_exception_is_wrapped_without_url(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28 for https://www.threads.com/share/ShareFake01/?xmt=FAKEXMTVALUE01')]);

        $e = $this->resolveExpectingFailure();
        $this->assertStringNotContainsString('xmt', $e->getMessage());
        $this->assertStringNotContainsString(self::CODE, $e->getMessage());
        $this->assertStringNotContainsString('http', $e->getMessage());
    }

    public function test_invalid_code_makes_no_request(): void
    {
        Http::fake();

        $this->expectException(ShareResolveException::class);
        try {
            app(ShareUrlResolver::class)->resolve('bad/code');
        } finally {
            Http::assertNothingSent();
        }
    }
}
