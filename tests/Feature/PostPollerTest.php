<?php

namespace Tests\Feature;

use App\Models\TrackedPost;
use App\Threads\FetchResult;
use App\Threads\PostData;
use App\Threads\PostPoller;
use App\Threads\PublicPageFetcher;
use App\Threads\Shortcode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PostPollerTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private const CODE = 'DFakeP0st01';

    private const URL = 'https://www.threads.com/t/DFakeP0st01';

    private CarbonImmutable $pagePub; // taken_at in fixtures

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'threads.track_hours' => 72,
            'threads.poll_bands' => [[0, 24, 60], [24, 72, 180]],
            'threads.max_consecutive_failures' => 5,
            'threads.retry_minutes' => 15,
        ]);
        $this->pagePub = CarbonImmutable::createFromTimestamp(1791000000, 'UTC'); // 2026-10-03 04:00:00Z
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/'.$name);
    }

    private function fakePage(string $fixture, int $status = 200): void
    {
        Http::fake([self::URL => Http::response($this->fixture($fixture), $status)]);
    }

    /** A post as W3's register creates it (shortcode-decoded publish time, slightly off). */
    private function registered(array $overrides = []): TrackedPost
    {
        $decoded = $this->pagePub->subSeconds(9);
        $createdAt = $overrides['created_at'] ?? $this->pagePub; // added to tracking right after publish
        unset($overrides['created_at']);

        $post = TrackedPost::create(array_merge([
            'url' => self::URL,
            'shortcode' => self::CODE,
            'pk' => Shortcode::toPk(self::CODE),
            'published_at' => $decoded,
            'published_at_source' => TrackedPost::PUBLISHED_FROM_SHORTCODE,
            'track_until' => $decoded->addHours(72),
            'status' => TrackedPost::STATUS_PENDING,
            'next_poll_at' => $decoded,
        ], $overrides));
        $post->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $post->refresh();
    }

    private function poll(TrackedPost $post, CarbonImmutable $now): TrackedPost
    {
        $this->app->make(PostPoller::class)->poll($post, $now);

        return $post->fresh();
    }

    private function snap(TrackedPost $post, CarbonImmutable $at, array $metrics = []): void
    {
        $post->snapshots()->create(array_merge([
            'captured_at' => $at, 'source' => 'public_page',
            'views' => 100, 'likes' => 10, 'replies' => 3, 'reposts' => 2, 'quotes' => 1, 'shares' => null,
        ], $metrics));
    }

    public function test_first_ok_poll_from_register_state_switches_to_page_time_and_tracks(): void
    {
        $this->fakePage('post_ok.html');
        $post = $this->registered(['pk' => null]);
        $now = $this->pagePub->addMinutes(30);

        $post = $this->poll($post, $now);

        $this->assertSame('tracking', $post->status);
        $this->assertSame('page', $post->published_at_source);
        $this->assertTrue($post->published_at->equalTo($this->pagePub));
        $this->assertTrue($post->track_until->equalTo($this->pagePub->addHours(72)));
        $this->assertTrue($post->next_poll_at->equalTo($this->pagePub->addMinutes(60)));
        $this->assertSame('example_user', $post->username);
        $this->assertSame(Shortcode::toPk(self::CODE), $post->pk);
        $this->assertTrue($post->last_captured_at->equalTo($now));
        $this->assertSame(0, $post->consecutive_failures);
        $this->assertNull($post->last_error);
        $this->assertFalse($post->counts_hidden);
        $this->assertNull($post->final_captured_at);

        $s = $post->snapshots()->sole();
        $this->assertTrue($s->captured_at->equalTo($now));
        $this->assertSame('0.50', $s->hours_since_publish);
        $this->assertSame([13410, 321, 17, 4, 2, 9], [$s->views, $s->likes, $s->replies, $s->reposts, $s->quotes, $s->shares]);
        $this->assertSame('public_page', $s->source);
        $this->assertNotNull($s->raw_json);
        $this->assertSame(self::CODE, json_decode($s->raw_json, true)['shortcode']);
    }

    public function test_successful_poll_clears_previous_failures(): void
    {
        $this->fakePage('post_ok.html');
        $post = $this->registered(['consecutive_failures' => 3, 'last_error' => '[blocked] x', 'status' => 'tracking']);

        $post = $this->poll($post, $this->pagePub->addHours(2));

        $this->assertSame(0, $post->consecutive_failures);
        $this->assertNull($post->last_error);
    }

    public function test_page_published_time_is_not_overwritten_once_from_page(): void
    {
        $this->fakePage('post_ok.html');
        $pagePub = $this->pagePub->subHour(); // pretend an earlier page read said so
        $post = $this->registered([
            'published_at' => $pagePub, 'published_at_source' => 'page', 'track_until' => $pagePub->addHours(72),
            'username' => 'kept_name',
        ]);
        $this->snap($post, $this->pagePub->addHours(4)); // not the first successful read

        $post = $this->poll($post, $this->pagePub->addHours(5));

        $this->assertTrue($post->published_at->equalTo($pagePub));
        $this->assertSame('kept_name', $post->username);
        $this->assertSame('6.00', $post->snapshots()->latest('id')->first()->hours_since_publish);
    }

    public function test_null_published_at_is_filled_from_page(): void
    {
        $this->fakePage('post_ok.html');
        $post = $this->registered(['published_at' => null, 'published_at_source' => null, 'track_until' => null]);

        $post = $this->poll($post, $this->pagePub->addHours(25));

        $this->assertSame('page', $post->published_at_source);
        $this->assertTrue($post->track_until->equalTo($this->pagePub->addHours(72)));
        $this->assertSame('tracking', $post->status);
        $this->assertTrue($post->next_poll_at->equalTo($this->pagePub->addHours(27)));
    }

    public function test_ok_poll_at_72h_writes_final_and_done(): void
    {
        $this->fakePage('post_null_shares.html');
        $post = $this->registered(['status' => 'tracking']);
        $this->snap($post, $this->pagePub->addHours(69));
        $now = $this->pagePub->addHours(72)->addMinutes(2);

        $post = $this->poll($post, $now);

        $this->assertSame('done', $post->status);
        $this->assertNull($post->next_poll_at);
        $this->assertTrue($post->final_captured_at->equalTo($now));
        $this->assertSame(250, $post->final_views);
        $this->assertSame(17, $post->final_replies);
        $this->assertNull($post->final_shares); // null stays null, never 0
        $this->assertFalse($post->added_after_window);
        $this->assertSame(2, $post->snapshots()->count());
    }

    public function test_added_after_window_single_poll_is_final_and_flagged(): void
    {
        $this->fakePage('post_ok.html');
        $post = $this->registered(['created_at' => $this->pagePub->addDays(10)->subMinute()]);
        $now = $this->pagePub->addDays(10);

        $post = $this->poll($post, $now);

        $this->assertSame('done', $post->status);
        $this->assertTrue($post->added_after_window);
        $this->assertSame(13410, $post->final_views);
        $this->assertTrue($post->final_captured_at->equalTo($now));
        $this->assertSame('240.00', $post->snapshots()->sole()->hours_since_publish);
    }

    public function test_counts_hidden_is_recorded_and_hidden_counts_stay_null(): void
    {
        $this->fakePage('post_counts_hidden.html');
        $post = $this->poll($this->registered(), $this->pagePub->addHour());
        $this->assertTrue($post->counts_hidden);
        $s = $post->snapshots()->sole();
        $this->assertNull($s->views);
        $this->assertNull($s->likes);
        $this->assertSame(17, $s->replies);
    }

    public function test_missing_views_stay_null(): void
    {
        $this->fakePage('post_missing_views.html');
        $post = $this->poll($this->registered(), $this->pagePub->addHour());
        $this->assertFalse($post->counts_hidden);
        $this->assertNull($post->snapshots()->sole()->views);
    }

    public function test_gone_with_snapshots_keeps_last_snapshot_as_final_at_its_own_time(): void
    {
        Http::fake([self::URL => Http::response('Not Found', 404)]);
        $post = $this->registered(['status' => 'tracking']);
        $this->snap($post, $this->pagePub->addHours(3), ['views' => 50]);
        $lastAt = $this->pagePub->addHours(4);
        $this->snap($post, $lastAt, ['views' => 80, 'shares' => null]);

        $post = $this->poll($post, $this->pagePub->addHours(5));

        $this->assertSame('gone', $post->status);
        $this->assertNull($post->next_poll_at);
        $this->assertSame('fetch.gone|{"status":404}', $post->last_error);
        $this->assertSame(80, $post->final_views);
        $this->assertNull($post->final_shares);
        $this->assertTrue($post->final_captured_at->equalTo($lastAt));
        $this->assertSame(2, $post->snapshots()->count());
    }

    public function test_gone_without_snapshots_has_no_final(): void
    {
        Http::fake([self::URL => Http::response('', 410)]);
        $post = $this->poll($this->registered(), $this->pagePub->addHour());

        $this->assertSame('gone', $post->status);
        $this->assertNull($post->final_captured_at);
        $this->assertNull($post->final_views);
    }

    public function test_transient_failure_retries_after_retry_minutes(): void
    {
        Http::fake([self::URL => Http::response('Too Many Requests', 429)]);
        $post = $this->registered(['status' => 'tracking', 'consecutive_failures' => 1]);
        $now = $this->pagePub->addHours(10);

        $post = $this->poll($post, $now);

        $this->assertSame('tracking', $post->status);
        $this->assertSame(2, $post->consecutive_failures);
        $this->assertSame('fetch.rate_limited|{"status":429}', $post->last_error);
        $this->assertTrue($post->next_poll_at->equalTo($now->addMinutes(15)));
        $this->assertSame(0, $post->snapshots()->count());
    }

    public function test_pending_post_stays_pending_on_failure(): void
    {
        $this->fakePage('login_wall.html');
        $post = $this->poll($this->registered(), $this->pagePub->addMinutes(5));

        $this->assertSame('pending', $post->status);
        $this->assertSame('fetch.blocked|{}', $post->last_error);
        $this->assertSame(1, $post->consecutive_failures);
    }

    public function test_retry_is_capped_at_track_until(): void
    {
        $this->fakePage('page_without_post.html');
        $post = $this->registered(['status' => 'tracking']);
        $now = $post->track_until->subMinutes(5);

        $post = $this->poll($post, $now);

        $this->assertSame('fetch.parse_failed|{}', $post->last_error);
        $this->assertTrue($post->next_poll_at->equalTo($post->track_until));
    }

    public function test_failure_at_72h_uses_last_snapshot_as_final_with_real_time(): void
    {
        Http::fake([self::URL => Http::response('err', 500)]);
        $post = $this->registered(['status' => 'tracking']);
        $lastAt = $this->pagePub->addHours(69);
        $this->snap($post, $lastAt, ['views' => 999, 'likes' => null]);

        $post = $this->poll($post, $post->track_until);

        $this->assertSame('done', $post->status);
        $this->assertNull($post->next_poll_at);
        $this->assertSame(999, $post->final_views);
        $this->assertNull($post->final_likes);
        $this->assertTrue($post->final_captured_at->equalTo($lastAt)); // not pretending it is 72h
        $this->assertSame('fetch.http_error|{"status":500}', $post->last_error);
    }

    public function test_failure_at_72h_without_snapshots_is_failed(): void
    {
        Http::fake([self::URL => Http::response('err', 500)]);
        $post = $this->registered(['status' => 'tracking']);

        $post = $this->poll($post, $post->track_until->addMinute());

        $this->assertSame('failed', $post->status);
        $this->assertNull($post->next_poll_at);
        $this->assertNull($post->final_captured_at);
    }

    public function test_too_many_consecutive_failures_is_failed_with_final_from_last_snapshot(): void
    {
        Http::fake([self::URL => Http::response('Too Many Requests', 429)]);
        $post = $this->registered(['status' => 'tracking', 'consecutive_failures' => 4]);
        $lastAt = $this->pagePub->addHours(2);
        $this->snap($post, $lastAt, ['views' => 77]);

        $post = $this->poll($post, $this->pagePub->addHours(8));

        $this->assertSame('failed', $post->status);
        $this->assertSame(5, $post->consecutive_failures);
        $this->assertNull($post->next_poll_at);
        $this->assertSame(77, $post->final_views);
        $this->assertTrue($post->final_captured_at->equalTo($lastAt));
        $this->assertSame('fetch.rate_limited|{"status":429}', $post->last_error);
    }

    public function test_unexpected_exception_is_recorded_not_thrown(): void
    {
        $fetcher = Mockery::mock(PublicPageFetcher::class);
        $fetcher->shouldReceive('fetch')->andThrow(new RuntimeException("boom\nline two\n#0 trace"));
        $this->app->instance(PublicPageFetcher::class, $fetcher);
        $post = $this->registered(['status' => 'tracking']);
        $now = $this->pagePub->addHours(3);

        $post = $this->poll($post, $now);

        $this->assertSame('exception|{"class":"RuntimeException"}', $post->last_error);
        $this->assertStringNotContainsString('boom', $post->last_error);
        $this->assertSame(1, $post->consecutive_failures);
        $this->assertSame('tracking', $post->status);
        $this->assertTrue($post->next_poll_at->equalTo($now->addMinutes(15)));
    }

    public function test_ok_without_any_publish_time_keeps_snapshot_but_counts_as_failure(): void
    {
        $data = new PostData(self::CODE, null, 'example_user', null, 5, 6, 7, 8, 9, null, false);
        $fetcher = Mockery::mock(PublicPageFetcher::class);
        $fetcher->shouldReceive('fetch')->andReturn(FetchResult::success(200, $data, '{"x":1}'));
        $this->app->instance(PublicPageFetcher::class, $fetcher);
        $post = $this->registered(['published_at' => null, 'published_at_source' => null, 'track_until' => null]);
        $now = $this->pagePub->addHours(3);

        $post = $this->poll($post, $now);

        $this->assertSame(1, $post->snapshots()->count());
        $this->assertNull($post->snapshots()->sole()->hours_since_publish);
        $this->assertSame(1, $post->consecutive_failures);
        $this->assertSame('poll.no_publish_time|{}', $post->last_error);
        $this->assertTrue($post->next_poll_at->equalTo($now->addMinutes(15)));
        $this->assertNull($post->track_until);
    }

    // ---- FX-A: V3-F6 / V3-F10 / V4-M5 ----

    public function test_first_successful_read_replaces_username_typed_in_the_url(): void
    {
        $this->fakePage('post_ok.html');
        $post = $this->registered([
            'username' => 'someone_famous',
            'url' => 'https://www.threads.com/@someone_famous/post/'.self::CODE,
        ]);

        $post = $this->poll($post, $this->pagePub->addMinutes(30));

        $this->assertSame('example_user', $post->username);
        $this->assertSame('https://www.threads.com/@example_user/post/'.self::CODE, $post->url);
    }

    public function test_full_exception_message_goes_to_the_log_not_the_page(): void
    {
        Log::spy();
        $fetcher = Mockery::mock(PublicPageFetcher::class);
        $fetcher->shouldReceive('fetch')->andThrow(new RuntimeException('SQLSTATE secret /srv/app/path'));
        $this->app->instance(PublicPageFetcher::class, $fetcher);

        $post = $this->poll($this->registered(['status' => 'tracking']), $this->pagePub->addHours(3));

        $this->assertSame('exception|{"class":"RuntimeException"}', $post->last_error);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $ctx['tracked_post_id'] === $post->id
            && str_contains($ctx['error'], 'SQLSTATE secret /srv/app/path'))->once();
        $this->actingAs(\App\Models\User::create(['name' => 'u', 'email' => 'u@example.test', 'password' => 'correct-horse-battery']));
        $post->users()->attach(\App\Models\User::sole()->id);
        $this->get('/posts/'.$post->id)->assertOk()->assertSee('內部錯誤，詳見伺服器 log')->assertDontSee('SQLSTATE');
    }

    public function test_added_after_window_follows_created_at_not_first_snapshot(): void
    {
        // Added before the window closed, but the first successful read only happens after it:
        // not "added after the window".
        $this->fakePage('post_ok.html');
        $post = $this->poll($this->registered(), $this->pagePub->addHours(80));
        $this->assertSame('done', $post->status);
        $this->assertFalse($post->added_after_window);
    }

    public function test_added_after_window_is_set_even_when_earlier_snapshots_exist(): void
    {
        // Shared post re-created late (created_at after track_until) with snapshots already present.
        $this->fakePage('post_ok.html');
        $post = $this->registered(['created_at' => $this->pagePub->addHours(73), 'status' => 'tracking']);
        $this->snap($post, $this->pagePub->addHours(74));

        $post = $this->poll($post, $this->pagePub->addHours(75));

        $this->assertSame('done', $post->status);
        $this->assertTrue($post->added_after_window);
    }

    public function test_added_after_window_is_set_when_the_final_poll_fails(): void
    {
        $this->fakePage('post_ok.html', 500);
        $post = $this->registered(['created_at' => $this->pagePub->addDays(5), 'status' => 'pending']);

        $post = $this->poll($post, $this->pagePub->addDays(5)->addMinute());

        $this->assertTrue($post->added_after_window);
    }
}
