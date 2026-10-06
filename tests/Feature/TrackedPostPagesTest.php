<?php

namespace Tests\Feature;

use App\Models\PostSnapshot;
use App\Models\TrackedPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrackedPostPagesTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private ?User $user = null;

    private function user(): User
    {
        return $this->user ??= User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'correct-horse-battery']);
    }

    private function login(): self
    {
        return $this->actingAs($this->user());
    }

    /** Creates a post in the (to-be) logged-in user's own list, unless $owned is false. */
    private function makePost(array $attrs = [], bool $owned = true): TrackedPost
    {
        $post = TrackedPost::create(array_merge([
            'url' => 'https://www.threads.com/@example_user/post/DFakeP0st01',
            'shortcode' => 'DFakeP0st01',
            'username' => 'example_user',
            'published_at' => CarbonImmutable::parse('2026-10-05 17:30:00', 'UTC'),
            'published_at_source' => 'page',
            'track_until' => CarbonImmutable::parse('2026-10-08 17:30:00', 'UTC'),
            'next_poll_at' => CarbonImmutable::parse('2026-10-05 18:30:00', 'UTC'),
            'status' => 'tracking',
        ], $attrs));
        if ($owned) {
            $this->user()->trackedPosts()->attach($post->id);
        }

        return $post;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->post('/posts', ['url' => 'https://www.threads.com/t/DFakeP0st01'])->assertRedirect('/login');
        $post = $this->makePost();
        $this->get('/posts/'.$post->id)->assertRedirect('/login');
        $this->delete('/posts/'.$post->id)->assertRedirect('/login');
        $this->assertSame(1, TrackedPost::count());
    }

    public function test_index_ok_when_empty(): void
    {
        $this->login()->get('/')->assertOk()->assertSee('貼上 Threads 貼文網址')->assertSee('還沒有追蹤任何貼文');
    }

    public function test_store_valid_url_redirects_and_lists_post(): void
    {
        $this->login()
            ->post('/posts', ['url' => 'https://www.threads.com/@another_user/post/DFakeP0st01'])
            ->assertRedirect('/');

        $this->assertSame(1, TrackedPost::count());
        $this->get('/')->assertOk()
            ->assertSee('@another_user')
            ->assertSee('已加入追蹤')
            ->assertSee('等待首次抓取')
            ->assertSee('推算')
            ->assertSee('https://www.threads.com/t/DFakeP0st01', false);

        // duplicate submission keeps a single row
        $this->post('/posts', ['url' => 'https://www.threads.net/@another_user/post/DFakeP0st01'])->assertRedirect('/');
        $this->assertSame(1, TrackedPost::count());
        $this->get('/')->assertSee('這篇貼文已經在你的清單裡。');
    }

    public function test_store_invalid_url_shows_error(): void
    {
        $this->login()
            ->from('/')
            ->post('/posts', ['url' => 'https://example.com/not-threads'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('url');

        $this->assertSame(0, TrackedPost::count());
        $this->get('/')->assertSee('無法辨識的 Threads 貼文網址');
    }

    public function test_store_share_url_resolves_and_lists_clean_post(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.threads.com/share/*' => Http::response('', 302, [
            'Location' => 'https://www.threads.com/@example_user/post/DFakeP0st01?xmt=FAKEXMTVALUE01&slof=1',
        ])]);

        $this->login()
            ->post('/posts', ['url' => 'https://www.threads.com/share/ShareFake01/'])
            ->assertRedirect('/');

        Http::assertSentCount(1);
        $this->assertSame(1, TrackedPost::count());
        $post = TrackedPost::first();
        $this->assertSame('https://www.threads.com/@example_user/post/DFakeP0st01', $post->url);
        $this->get('/')->assertOk()
            ->assertSee('@example_user')
            ->assertSee('已加入追蹤')
            ->assertDontSee('ShareFake01')
            ->assertDontSee('xmt');
    }

    public function test_store_share_url_resolve_failure_shows_chinese_error(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.threads.com/share/*' => Http::response('', 404)]);

        $this->login()
            ->from('/')
            ->post('/posts', ['url' => 'https://www.threads.com/share/ShareFake01/'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('url');

        Http::assertSentCount(1);
        $this->assertSame(0, TrackedPost::count());
        $this->get('/')->assertSee('這個分享連結已失效或貼文已刪除');
    }

    public function test_store_empty_url_shows_error(): void
    {
        $this->login()->from('/')->post('/posts', ['url' => ''])->assertSessionHasErrors('url');
        $this->assertSame(0, TrackedPost::count());
    }

    public function test_published_time_is_shown_in_taipei_timezone(): void
    {
        config(['threads.display_timezone' => 'Asia/Taipei']);
        $this->makePost();

        $this->login()->get('/')->assertOk()
            ->assertSee('2026-10-06 01時')
            ->assertDontSee('2026-10-05 17時')
            ->assertDontSee('推算');
    }

    public function test_index_shows_latest_snapshot_with_null_as_unavailable(): void
    {
        $post = $this->makePost();
        $post->snapshots()->create(['captured_at' => CarbonImmutable::parse('2026-10-05 18:00:00', 'UTC'), 'source' => 'public_page',
            'hours_since_publish' => 0.5, 'views' => 100, 'replies' => 1, 'reposts' => 0, 'shares' => 0]);
        $post->snapshots()->create(['captured_at' => CarbonImmutable::parse('2026-10-05 19:00:00', 'UTC'), 'source' => 'public_page',
            'hours_since_publish' => 1.5, 'views' => 4321, 'replies' => 7, 'reposts' => 2, 'shares' => null]);

        $this->login()->get('/')->assertOk()
            ->assertSee('目前')
            ->assertSee('4,321')
            ->assertSee('無法取得')
            ->assertSee('展開分時紀錄（2 筆）');

        // The summary above the breakdown shows only the latest reading; the old 100 is only in <details>.
        $html = $this->get('/')->getContent();
        $summary = substr($html, 0, (int) strpos($html, '<details class="history">'));
        $this->assertStringContainsString('4,321', $summary);
        $this->assertStringNotContainsString('>100<', $summary);
    }

    public function test_done_post_shows_final_numbers_and_flags(): void
    {
        $this->makePost([
            'status' => 'done', 'added_after_window' => true,
            'final_views' => 98765, 'final_replies' => 12, 'final_reposts' => 3, 'final_shares' => null,
            'final_captured_at' => CarbonImmutable::parse('2026-10-08 18:10:00', 'UTC'),
        ]);

        $this->login()->get('/')->assertOk()
            ->assertSee('已完成')
            ->assertSee('72 小時最終')
            ->assertSee('98,765')
            ->assertSee('無法取得')
            ->assertSee('加入時已超過 72 小時，非 72 小時那一刻的數字')
            ->assertSee('最終數字取自 2026-10-09 02:10');
    }

    public function test_failed_post_shows_last_error_and_hidden_counts(): void
    {
        $this->makePost(['status' => 'failed', 'last_error' => 'HTTP 429 rate limited', 'counts_hidden' => true]);

        $this->login()->get('/')->assertOk()
            ->assertSee('失敗')
            ->assertSee('HTTP 429 rate limited')
            ->assertSee('作者隱藏數字');
    }

    public function test_gone_status_label(): void
    {
        $this->makePost(['status' => 'gone']);
        $this->login()->get('/')->assertSee('貼文已刪除或不公開');
    }

    public function test_show_displays_snapshots_and_chart(): void
    {
        $post = $this->makePost();
        $post->snapshots()->create(['captured_at' => CarbonImmutable::parse('2026-10-05 18:00:00', 'UTC'), 'source' => 'public_page',
            'hours_since_publish' => 0.5, 'views' => 100, 'likes' => 5, 'replies' => 1, 'reposts' => 0, 'quotes' => 0, 'shares' => null]);
        $post->snapshots()->create(['captured_at' => CarbonImmutable::parse('2026-10-05 19:00:00', 'UTC'), 'source' => 'public_page',
            'hours_since_publish' => 1.5, 'views' => null, 'likes' => 9]);
        $post->snapshots()->create(['captured_at' => CarbonImmutable::parse('2026-10-05 20:00:00', 'UTC'), 'source' => 'public_page',
            'hours_since_publish' => 2.5, 'views' => 2500, 'likes' => 11, 'quotes' => 4]);

        $res = $this->login()->get('/posts/'.$post->id)->assertOk()
            ->assertSee('抓取紀錄（3 筆）')
            ->assertSee('2026-10-06 02:00:00')   // 18:00 UTC in Taipei
            ->assertSee('2026-10-06 01時')
            ->assertSee('2026-10-06 01:30:00')
            ->assertSee('2,500')
            ->assertSee('無法取得')
            ->assertSee('引用')
            ->assertSee('<svg class="chart"', false)
            ->assertSee('confirm(', false)
            ->assertSee('從我的清單移除');

        // the null-views snapshot is skipped: exactly two points on the polyline
        preg_match('/<polyline class="line" points="([^"]+)"/', $res->getContent(), $m);
        $this->assertCount(2, explode(' ', trim($m[1])));
    }

    public function test_show_without_snapshots(): void
    {
        $post = $this->makePost(['status' => 'pending']);
        $this->login()->get('/posts/'.$post->id)->assertOk()->assertSee('尚未抓取')->assertSee('還沒有可畫的觀看數資料');
    }

    public function test_show_missing_post_is_404(): void
    {
        $this->login()->get('/posts/999')->assertNotFound();
    }

    public function test_destroy_deletes_post_and_snapshots(): void
    {
        $post = $this->makePost();
        $post->snapshots()->create(['captured_at' => CarbonImmutable::now('UTC'), 'source' => 'public_page', 'views' => 1]);
        $keep = $this->makePost(['shortcode' => 'DFakeP0st02', 'url' => 'https://www.threads.com/t/DFakeP0st02']);
        $keep->snapshots()->create(['captured_at' => CarbonImmutable::now('UTC'), 'source' => 'public_page', 'views' => 2]);

        $this->login()->delete('/posts/'.$post->id)->assertRedirect('/');

        $this->assertNull(TrackedPost::find($post->id));
        $this->assertSame(0, PostSnapshot::where('tracked_post_id', $post->id)->count());
        $this->assertSame(1, PostSnapshot::count());
        $this->get('/')->assertSee('已從你的清單移除。');
    }
}
