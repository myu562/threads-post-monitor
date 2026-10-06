<?php

namespace Tests\Unit\Threads;

use App\Models\TrackedPost;
use App\Models\User;
use App\Support\UserMessage;
use App\Threads\Exceptions\ShareResolveException;
use App\Threads\TrackPostService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class TrackPostServiceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.threads.com/@example_user/post/DFakeP0st01?xmt=FAKEXMTABC';

    private const CLEAN_URL = 'https://www.threads.com/@example_user/post/DFakeP0st01';

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function user(): User
    {
        return $this->user ??= User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => 'correct-horse-battery']);
    }

    public function test_register_creates_post_with_contract_fields(): void
    {
        config(['threads.track_hours' => 72]);
        $this->travelTo(CarbonImmutable::parse('2025-01-29 18:00:00', 'UTC'));

        $post = app(TrackPostService::class)->register('  '.self::URL.' ', $this->user())->refresh();

        $this->assertSame(self::CLEAN_URL, $post->url);
        $this->assertSame('DFakeP0st01', $post->shortcode);
        $this->assertSame('example_user', $post->username);
        $this->assertSame('public_page', $post->source);
        $this->assertSame('3556315264011394357', $post->pk);
        $this->assertSame('2025-01-29 15:50:37', $post->published_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('shortcode_decode', $post->published_at_source);
        $this->assertSame('2025-02-01 15:50:37', $post->track_until->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('pending', $post->status);
        $this->assertSame('2025-01-29 18:00:00', $post->next_poll_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_short_t_url_has_null_username(): void
    {
        $post = app(TrackPostService::class)->register('threads.com/t/DFakeP0st01?igshid=fake', $this->user());

        $this->assertNull($post->username);
        $this->assertSame('https://www.threads.com/t/DFakeP0st01', $post->url);
        $this->assertSame('DFakeP0st01', $post->shortcode);
    }

    public function test_duplicate_returns_existing_row_without_reset(): void
    {
        $service = app(TrackPostService::class);
        $first = $service->register(self::URL, $this->user());
        $first->update(['status' => 'tracking', 'final_views' => 123, 'consecutive_failures' => 2]);

        $again = $service->register('https://www.threads.net/@example_user/post/DFakeP0st01', $this->user());

        $this->assertSame($first->id, $again->id);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertSame(1, TrackedPost::count());
        $fresh = $again->refresh();
        $this->assertSame('tracking', $fresh->status);
        $this->assertSame(123, $fresh->final_views);
        $this->assertSame(2, $fresh->consecutive_failures);
        $this->assertSame(self::CLEAN_URL, $fresh->url);
        $this->assertSame(1, $this->user()->trackedPosts()->count());
    }

    public function test_invalid_url_throws_with_chinese_message(): void
    {
        try {
            app(TrackPostService::class)->register('https://example.com/@a/post/DFakeP0st01', $this->user());
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('errors.url.unrecognized', $e->getMessage());
            app()->setLocale('zh_TW');
            $this->assertStringContainsString('無法辨識', UserMessage::of($e));
        }
        $this->assertSame(0, TrackedPost::count());
        $this->assertSame(0, $this->user()->trackedPosts()->count());
    }

    public function test_share_url_is_resolved_and_only_clean_url_is_stored(): void
    {
        Http::fake(['www.threads.com/share/*' => Http::response('', 302, [
            'Location' => 'https://www.threads.com/@example_user/post/DFakeP0st01?xmt=FAKEXMTVALUE01&slof=1',
        ])]);

        $post = app(TrackPostService::class)->register('https://www.threads.com/share/ShareFake01/?xmt=FAKEXMTVALUE02', $this->user())->refresh();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.threads.com/share/ShareFake01/');
        $this->assertSame('DFakeP0st01', $post->shortcode);
        $this->assertSame('example_user', $post->username);
        $this->assertSame('https://www.threads.com/@example_user/post/DFakeP0st01', $post->url);
        $this->assertSame(1, $this->user()->trackedPosts()->count());

        $this->assertDatabaseHasNoTrace(['share/', 'ShareFake01', 'xmt', 'FAKEXMT', 'slof']);
    }

    public function test_share_url_for_already_tracked_post_only_attaches(): void
    {
        $service = app(TrackPostService::class);
        $other = User::create(['name' => 'O', 'email' => 'o@example.test', 'password' => 'correct-horse-battery']);
        $first = $service->register(self::URL, $other);
        $first->update(['status' => 'tracking', 'consecutive_failures' => 1]);

        Http::fake(['www.threads.com/share/*' => Http::response('', 302, [
            'Location' => 'https://www.threads.com/@example_user/post/DFakeP0st01?xmt=FAKEXMTVALUE03',
        ])]);

        $again = $service->register('https://threads.net/share/ShareFake02', $this->user());

        Http::assertSentCount(1);
        $this->assertSame($first->id, $again->id);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertTrue($service->lastAttached);
        $this->assertSame(1, TrackedPost::count());
        $this->assertSame('tracking', $again->refresh()->status);
        $this->assertSame(1, $again->consecutive_failures);
        $this->assertSame(1, $this->user()->trackedPosts()->count());
    }

    public function test_share_url_resolve_failure_throws_and_writes_nothing(): void
    {
        Http::fake(['www.threads.com/share/*' => Http::response('', 404)]);

        try {
            app(TrackPostService::class)->register('https://www.threads.com/share/ShareFake03/', $this->user());
            $this->fail('Expected ShareResolveException');
        } catch (ShareResolveException $e) {
            $this->assertSame('errors.share.gone', $e->getMessage());
            app()->setLocale('zh_TW');
            $this->assertStringContainsString('分享連結', UserMessage::of($e));
        }
        Http::assertSentCount(1);
        $this->assertSame(0, TrackedPost::count());
        $this->assertSame(0, $this->user()->trackedPosts()->count());
    }

    /** @param list<string> $needles */
    private function assertDatabaseHasNoTrace(array $needles): void
    {
        foreach (['tracked_posts', 'tracked_post_user', 'post_snapshots'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                foreach ((array) $row as $col => $value) {
                    foreach ($needles as $needle) {
                        $this->assertStringNotContainsString($needle, (string) $value, "{$table}.{$col} contains {$needle}");
                    }
                }
            }
        }
    }
}
