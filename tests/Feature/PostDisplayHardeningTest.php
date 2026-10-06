<?php

namespace Tests\Feature;

use App\Models\TrackedPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** FX-A: V3-F5 (neutral flashes), V4-M6 / V4-M7 (display flags), V4-M11 (no raw input flashed), V1-F10 (app name). */
class PostDisplayHardeningTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function user(string $email = 'a@example.test'): User
    {
        return User::firstOrCreate(['email' => $email], ['name' => 'u', 'password' => 'correct-horse-battery']);
    }

    private function makePost(array $attrs = []): TrackedPost
    {
        $post = TrackedPost::create(array_merge([
            'url' => 'https://www.threads.com/@example_user/post/DFakeP0st01',
            'shortcode' => 'DFakeP0st01',
            'username' => 'example_user',
            'published_at' => CarbonImmutable::parse('2026-10-05 17:30:00', 'UTC'),
            'published_at_source' => 'page',
            'track_until' => CarbonImmutable::parse('2026-10-08 17:30:00', 'UTC'),
            'status' => 'tracking',
        ], $attrs));
        $this->user()->trackedPosts()->attach($post->id);

        return $post;
    }

    // ---- V3-F5 ----

    public function test_add_and_remove_messages_do_not_reveal_other_trackers(): void
    {
        Http::fake();
        $url = 'https://www.threads.com/@example_user/post/DFakeP0st01';
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');

        // First tracker and a later one get the same message.
        $this->actingAs($a)->post('/posts', ['url' => $url])->assertSessionHas('status', '已加入追蹤。');
        $this->actingAs($b)->post('/posts', ['url' => $url])->assertSessionHas('status', '已加入追蹤。');
        $this->actingAs($b)->post('/posts', ['url' => $url])->assertSessionHas('status', '這篇貼文已經在你的清單裡。');

        $post = TrackedPost::sole();
        // Removing while someone else still tracks it, and removing as the last tracker: same message.
        $this->actingAs($a)->delete('/posts/'.$post->id)->assertSessionHas('status', '已從你的清單移除。');
        $this->actingAs($b)->delete('/posts/'.$post->id)->assertSessionHas('status', '已從你的清單移除。');
        $this->assertNull(TrackedPost::find($post->id));
    }

    // ---- V4-M6: counts_hidden only covers likes and views ----

    public function test_counts_hidden_only_labels_likes_and_views(): void
    {
        $post = $this->makePost(['counts_hidden' => true]);
        $this->assertSame('作者隱藏數字', $post->formatMetric(null, 'likes'));
        $this->assertSame('作者隱藏數字', $post->formatMetric(null, 'views'));
        foreach (['replies', 'reposts', 'quotes', 'shares'] as $m) {
            $this->assertSame('無法取得', $post->formatMetric(null, $m));
        }
        $post->counts_hidden = false;
        $this->assertSame('無法取得', $post->formatMetric(null, 'views'));
    }

    public function test_counts_hidden_rule_applies_to_summary_and_history_table(): void
    {
        $post = $this->makePost(['counts_hidden' => true]);
        $post->snapshots()->create(['captured_at' => CarbonImmutable::parse('2026-10-05 18:00:00', 'UTC'), 'source' => 'public_page',
            'hours_since_publish' => 0.5, 'views' => null, 'likes' => null, 'replies' => 4, 'reposts' => null, 'quotes' => 1, 'shares' => 2]);

        $html = $this->actingAs($this->user())->get('/posts/'.$post->id)->assertOk()->getContent();
        // Summary (6 metrics) + history row (6 cells): views & likes hidden twice each, reposts unavailable twice.
        $this->assertSame(4, substr_count($html, '作者隱藏數字'));
        $this->assertSame(2, substr_count($html, '無法取得'));

        $index = $this->get('/')->assertOk()->getContent();
        // Only views is on the list card: once in the summary, once in the (collapsed) hourly breakdown.
        $this->assertSame(2, substr_count($index, '作者隱藏數字'));
        $this->assertSame(2, substr_count($index, '無法取得'));    // reposts, same two places
    }

    // ---- V4-M7: gone / failed show their last numbers ----

    public function test_gone_post_with_final_numbers_shows_them(): void
    {
        $this->makePost([
            'status' => 'gone', 'final_views' => 5555, 'final_replies' => 3,
            'final_captured_at' => CarbonImmutable::parse('2026-10-06 04:20:00', 'UTC'),
        ]);
        config(['threads.display_timezone' => 'Asia/Taipei']);

        $this->actingAs($this->user())->get('/')->assertOk()
            ->assertSee('刪除前最後數字')
            ->assertSee('5,555')
            ->assertSee('取自 2026-10-06 12:20');
    }

    public function test_failed_post_with_final_numbers_shows_them(): void
    {
        $post = $this->makePost([
            'status' => 'failed', 'final_views' => 777, 'last_error' => '[blocked] HTTP 429',
            'final_captured_at' => CarbonImmutable::parse('2026-10-06 04:20:00', 'UTC'),
        ]);
        $post->snapshots()->create(['captured_at' => CarbonImmutable::parse('2026-10-06 05:00:00', 'UTC'), 'source' => 'public_page', 'views' => 1]);
        config(['threads.display_timezone' => 'Asia/Taipei']);

        $this->actingAs($this->user())->get('/posts/'.$post->id)->assertOk()
            ->assertSee('失敗前最後數字')
            ->assertSee('777')
            ->assertSee('取自 2026-10-06 12:20:00');
    }

    public function test_gone_or_failed_without_final_numbers_fall_back_to_latest_snapshot(): void
    {
        $post = $this->makePost(['status' => 'failed']);
        $this->assertSame('目前', $post->displayMetrics()['label']);
        $post->status = 'gone';
        $this->assertSame('目前', $post->displayMetrics()['label']);
        $post->status = 'done';
        $this->assertSame('72 小時最終', $post->displayMetrics()['label']);
    }

    // ---- V4-M11: failed add does not flash the raw input ----

    public function test_failed_share_link_is_not_flashed_back(): void
    {
        Http::fake(['www.threads.com/share/*' => Http::response('', 404)]);

        $this->actingAs($this->user())->from('/')
            ->post('/posts', ['url' => 'https://www.threads.com/share/ShareFake01/?xmt=FAKEXMTVALUE01'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('url');

        $this->assertNull(session()->getOldInput('url'));
        $this->get('/')->assertDontSee('ShareFake01')->assertDontSee('FAKEXMTVALUE01');
    }

    public function test_failed_other_url_is_flashed_without_query_or_fragment(): void
    {
        $this->actingAs($this->user())->from('/')
            ->post('/posts', ['url' => 'https://example.com/some/page?token=SECRETVALUE#frag'])
            ->assertSessionHasErrors('url');

        $this->assertSame('https://example.com/some/page', session()->getOldInput('url'));
        $this->get('/')->assertSee('value="https://example.com/some/page"', false)->assertDontSee('SECRETVALUE');
    }

    public function test_validation_failure_does_not_flash_the_raw_input(): void
    {
        $long = 'https://example.com/x?q='.str_repeat('A', 2100);
        $this->actingAs($this->user())->from('/')->post('/posts', ['url' => $long])->assertSessionHasErrors('url');

        $this->assertSame('https://example.com/x', session()->getOldInput('url'));
    }

    // ---- V1-F10 ----

    public function test_product_name_comes_from_app_name(): void
    {
        config(['app.name' => 'My Custom Monitor']);

        $this->withHeader('Accept-Language', 'en')->get('/login')->assertOk()
            ->assertSee('<title>Log in · My Custom Monitor</title>', false)
            ->assertSee('My Custom Monitor')
            ->assertDontSee('Threads Tracker');

        $this->withHeader('Accept-Language', 'zh-TW')->actingAs($this->user())->get('/')->assertOk()
            ->assertSee('<title>追蹤清單 · My Custom Monitor</title>', false)
            ->assertDontSee('Threads Tracker');
    }
}
