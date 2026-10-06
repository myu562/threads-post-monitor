<?php

namespace Tests\Feature;

use App\Models\TrackedPost;
use App\Models\User;
use App\Threads\PostPoller;
use App\Threads\Shortcode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Contracts K1 (hourly breakdown with increments) and K2 (caption). */
class PostCaptionAndDeltasTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private const CODE = 'DFakeP0st01';

    private const URL = 'https://www.threads.com/t/DFakeP0st01';

    private const CAPTION = "假的測試內文第一行\n第二行 <b>not bold</b> & 100% done_\n\n#假標籤 結尾";

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['threads.display_timezone' => 'Asia/Taipei']);
    }

    private function user(): User
    {
        return User::firstOrCreate(['email' => 'a@example.test'], ['name' => 'a', 'password' => 'correct-horse-battery']);
    }

    private function makePost(array $attrs = []): TrackedPost
    {
        $post = TrackedPost::create(array_merge([
            'url' => self::URL, 'shortcode' => self::CODE, 'username' => 'example_user',
            'published_at' => CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'), 'published_at_source' => 'page',
            'track_until' => CarbonImmutable::parse('2026-10-08 17:00:00', 'UTC'),
            'next_poll_at' => CarbonImmutable::parse('2026-10-05 21:00:00', 'UTC'), 'status' => 'tracking',
        ], $attrs));
        $this->user()->trackedPosts()->attach($post->id);

        return $post;
    }

    private function fakePage(string $fixture): void
    {
        Http::fake([self::URL => Http::response(file_get_contents(__DIR__.'/../Fixtures/'.$fixture), 200)]);
    }

    private function snaps(TrackedPost $post): void
    {
        $rows = [
            ['2026-10-05 18:00:00', 1.0, ['views' => 100, 'likes' => 10, 'replies' => 2, 'reposts' => 1, 'quotes' => 0, 'shares' => null]],
            ['2026-10-05 19:00:00', 2.0, ['views' => 160, 'likes' => 12, 'replies' => 8, 'reposts' => 1, 'quotes' => 0, 'shares' => 4]],
            ['2026-10-05 19:15:00', 2.25, ['views' => 170, 'likes' => 11, 'replies' => 5, 'reposts' => 1, 'quotes' => 0, 'shares' => 4]],
            ['2026-10-05 22:15:00', 5.25, ['views' => 470, 'likes' => 20, 'replies' => 5, 'reposts' => 4, 'quotes' => 3, 'shares' => 7]],
        ];
        foreach ($rows as [$at, $h, $m]) {
            $post->snapshots()->create(array_merge(['captured_at' => CarbonImmutable::parse($at, 'UTC'),
                'hours_since_publish' => $h, 'source' => 'public_page'], $m));
        }
    }

    // ---- K2 ----

    public function test_poller_writes_caption_once_and_never_overwrites(): void
    {
        $post = $this->makePost(['pk' => Shortcode::toPk(self::CODE), 'status' => 'pending']);
        $poller = $this->app->make(PostPoller::class);
        $now = CarbonImmutable::createFromTimestamp(1791000000, 'UTC')->addMinutes(30);

        $this->fakePage('post_with_caption.html');
        $poller->poll($post, $now);
        $this->assertSame(self::CAPTION, $post->fresh()->caption);

        // A later page with a different caption does not overwrite it.
        Http::fake([self::URL => Http::response(str_replace('假的測試內文第一行', '改過的假內文', file_get_contents(__DIR__.'/../Fixtures/post_with_caption.html')), 200)]);
        $poller->poll($post->fresh(), $now->addHour());
        $this->assertSame(self::CAPTION, $post->fresh()->caption);
        $this->assertSame(2, $post->snapshots()->count());
    }

    public function test_poller_fills_caption_later_when_first_reading_had_none(): void
    {
        $post = $this->makePost(['pk' => Shortcode::toPk(self::CODE), 'status' => 'pending']);
        $poller = $this->app->make(PostPoller::class);
        $now = CarbonImmutable::createFromTimestamp(1791000000, 'UTC')->addMinutes(30);
        $html = file_get_contents(__DIR__.'/../Fixtures/post_with_caption.html');

        Http::fake([self::URL => Http::sequence()
            ->push(preg_replace('/"caption":\{"has_translation":false,"pk":"17900000000000001"[^}]*\}/', '"caption":null', $html), 200)
            ->push($html, 200)]);

        $poller->poll($post, $now);
        $this->assertNull($post->fresh()->caption);
        $poller->poll($post->fresh(), $now->addHour());
        $this->assertSame(self::CAPTION, $post->fresh()->caption);
    }

    public function test_detail_page_shows_escaped_caption_above_metrics(): void
    {
        $post = $this->makePost(['caption' => self::CAPTION]);

        $html = $this->actingAs($this->user())->get('/posts/'.$post->id)->assertOk()->getContent();

        $this->assertStringContainsString('&lt;b&gt;not bold&lt;/b&gt; &amp; 100% done_', $html);
        $this->assertStringNotContainsString('<b>not bold</b>', $html);
        $this->assertStringContainsString("假的測試內文第一行\n第二行", $html); // newline kept, pre-wrap shows it
        $this->assertLessThan(strpos($html, 'class="metrics"'), strpos($html, 'class="caption"'));
        $this->assertStringNotContainsString('展開全文', $html);
    }

    public function test_long_caption_is_folded_with_details(): void
    {
        $long = str_repeat('假字', 300).'結尾記號';  // 604 characters
        $post = $this->makePost(['caption' => $long]);

        $html = $this->actingAs($this->user())->get('/posts/'.$post->id)->assertOk()->getContent();

        $this->assertStringContainsString('<details class="caption-fold">', $html);
        $this->assertStringContainsString('展開全文（604 字）', $html);
        $this->assertStringContainsString(mb_substr($long, 0, 500).'…', $html);
        $this->assertSame(1, substr_count($html, '結尾記號')); // full text only once, inside <details>
    }

    public function test_no_caption_renders_nothing(): void
    {
        $post = $this->makePost();
        $html = $this->actingAs($this->user())->get('/posts/'.$post->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('class="caption', $html);
    }

    // ---- K1 ----

    public function test_index_card_has_hourly_breakdown_newest_first_with_increments(): void
    {
        $post = $this->makePost();
        $this->snaps($post);

        $html = $this->actingAs($this->user())->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<summary>展開分時紀錄（4 筆）</summary>', $html);

        $details = substr($html, strpos($html, '<details class="history">'));
        $details = substr($details, 0, strpos($details, '</details>'));

        // Newest first, display timezone, Y-m-d H:i.
        $t1 = strpos($details, '2026-10-06 06:15');
        $t2 = strpos($details, '2026-10-06 03:15');
        $t3 = strpos($details, '2026-10-06 03:00');
        $t4 = strpos($details, '2026-10-06 02:00');
        $this->assertTrue($t1 < $t2 && $t2 < $t3 && $t3 < $t4);

        // 22:15 vs 19:15 (3h): views +300 → 每小時 +100; replies ±0; reposts +3 → +1/h; shares +3 → +1/h.
        $this->assertStringContainsString('<div class="delta delta-up" title="增加">+300</div>', $details);
        $this->assertStringContainsString('每小時 +100', $details);
        $this->assertStringContainsString('<div class="delta delta-flat" title="無變化">±0</div>', $details);
        // 19:15 vs 19:00 (0.25h): replies −3, no per-hour figure for that row.
        $this->assertStringContainsString("<div class=\"delta delta-down\" title=\"減少\">\u{2212}3</div>", $details);
        $this->assertStringNotContainsString('每小時 +40', $details); // views +10 in 15 minutes is not extrapolated
        // 19:00 vs 18:00: shares null before → —
        $this->assertStringContainsString('<div class="delta delta-flat" title="無法計算增量">—</div>', $details);
        // Card only lists views / replies / reposts / shares.
        $this->assertStringNotContainsString('>讚<', $details);
        $this->assertStringContainsString('5.25 小時', $details);
    }

    public function test_index_has_no_breakdown_without_snapshots(): void
    {
        $this->makePost();
        $html = $this->actingAs($this->user())->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('展開分時紀錄', $html);
        $this->assertStringNotContainsString('<details class="history"', $html);
    }

    public function test_detail_table_has_increments_and_rates_for_all_six_metrics(): void
    {
        $post = $this->makePost();
        $this->snaps($post);

        $html = $this->actingAs($this->user())->get('/posts/'.$post->id)->assertOk()->getContent();
        $table = substr($html, strpos($html, '<table>'));
        $table = substr($table, 0, strpos($table, '</table>'));

        // 4 snapshots × 6 metrics = 24 change cells.
        $this->assertSame(24, preg_match_all('/<div class="delta delta-(up|down|flat)" title=/', $table));
        // likes: +2, −1, +9; quotes +3 over 3h → 每小時 +1; likes +9 over 3h → 每小時 +3
        $this->assertStringContainsString("\u{2212}1</div>", $table);
        $this->assertStringContainsString('+9</div>', $table);
        $this->assertStringContainsString('每小時 +3', $table);
        $this->assertStringContainsString('間隔 3 小時', $table);
        $this->assertStringContainsString('間隔 0.25 小時', $table);
    }
}
