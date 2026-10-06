<?php

namespace Tests\Feature;

use App\Models\PostSnapshot;
use App\Models\TrackedPost;
use App\Threads\PostPoller;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PollDueCommandTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['threads.politeness_sleep_ms' => 0]);
        $this->now = CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC');
        CarbonImmutable::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makePost(string $code, ?CarbonImmutable $nextPollAt, string $status = 'tracking'): TrackedPost
    {
        $pub = $this->now->subHours(6);

        return TrackedPost::create([
            'url' => 'https://www.threads.com/t/'.$code, 'shortcode' => $code,
            'published_at' => $pub, 'published_at_source' => 'shortcode_decode', 'track_until' => $pub->addHours(72),
            'status' => $status, 'next_poll_at' => $nextPollAt,
        ]);
    }

    /** Records which posts were polled; optionally throws for some shortcodes. */
    private function spyPoller(array $throwFor = []): object
    {
        $spy = new class($throwFor) extends PostPoller
        {
            public array $polled = [];

            public function __construct(private array $throwFor) {}

            public function poll(TrackedPost $post, ?CarbonImmutable $now = null): void
            {
                $this->polled[] = $post->shortcode;
                if (in_array($post->shortcode, $this->throwFor, true)) {
                    throw new RuntimeException('unexpected');
                }
            }
        };
        $this->app->instance(PostPoller::class, $spy);

        return $spy;
    }

    public function test_selects_only_due_pending_or_tracking_in_next_poll_order(): void
    {
        $this->makePost('DDueLater01', $this->now->subMinutes(1));
        $this->makePost('DDueFirst01', $this->now->subHours(2), 'pending');
        $this->makePost('DDueExact01', $this->now);
        $this->makePost('DFuture0001', $this->now->addMinute());
        $this->makePost('DNullNext01', null);
        $this->makePost('DDoneOld001', $this->now->subHours(5), 'done');
        $this->makePost('DGoneOld001', $this->now->subHours(5), 'gone');
        $this->makePost('DFailOld001', $this->now->subHours(5), 'failed');
        $spy = $this->spyPoller();

        $this->artisan('threads:poll-due')->assertExitCode(0);

        $this->assertSame(['DDueFirst01', 'DDueLater01', 'DDueExact01'], $spy->polled);
    }

    public function test_respects_limit(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makePost('DLimit000'.$i, $this->now->subMinutes(10 - $i));
        }
        $spy = $this->spyPoller();

        $this->artisan('threads:poll-due', ['--limit' => 2])->assertExitCode(0);

        $this->assertSame(['DLimit0001', 'DLimit0002'], $spy->polled);
    }

    public function test_one_post_throwing_does_not_stop_the_next(): void
    {
        $this->makePost('DBoom000001', $this->now->subMinutes(3));
        $this->makePost('DAfter00001', $this->now->subMinutes(2));
        $spy = $this->spyPoller(['DBoom000001']);

        $this->artisan('threads:poll-due')->assertExitCode(0);

        $this->assertSame(['DBoom000001', 'DAfter00001'], $spy->polled);
    }

    public function test_end_to_end_with_real_poller_one_failure_one_success(): void
    {
        $fixture = file_get_contents(__DIR__.'/../Fixtures/post_ok.html');
        Http::fake([
            'https://www.threads.com/t/DFakeP0st01' => Http::response($fixture, 200),
            'https://www.threads.com/t/DBlocked001' => Http::response('Too Many Requests', 429),
        ]);
        $blocked = $this->makePost('DBlocked001', $this->now->subMinutes(5));
        $ok = $this->makePost('DFakeP0st01', $this->now->subMinutes(1), 'pending');

        $this->artisan('threads:poll-due')->assertExitCode(0);

        $blocked->refresh();
        $ok->refresh();
        $this->assertSame(1, $blocked->consecutive_failures);
        $this->assertSame('fetch.rate_limited|{"status":429}', $blocked->last_error);
        $this->assertSame('tracking', $ok->status);
        $this->assertSame(1, $ok->snapshots()->count());
        $this->assertTrue($ok->last_captured_at->equalTo($this->now));
        Http::assertSentCount(2);
    }

    public function test_prune_raw_nulls_only_old_raw_json(): void
    {
        config(['threads.snapshot_raw_retention_days' => 7]);
        $post = $this->makePost('DPrune00001', null);
        $old = $post->snapshots()->create(['captured_at' => $this->now->subDays(8), 'source' => 'public_page', 'raw_json' => '{"a":1}', 'views' => 5]);
        $edge = $post->snapshots()->create(['captured_at' => $this->now->subDays(7)->addMinute(), 'source' => 'public_page', 'raw_json' => '{"b":1}']);
        $new = $post->snapshots()->create(['captured_at' => $this->now->subHour(), 'source' => 'public_page', 'raw_json' => '{"c":1}']);

        $this->artisan('threads:prune-raw')->assertExitCode(0);

        $this->assertNull($old->fresh()->raw_json);
        $this->assertSame(5, $old->fresh()->views); // metrics untouched
        $this->assertSame('{"b":1}', $edge->fresh()->raw_json);
        $this->assertSame('{"c":1}', $new->fresh()->raw_json);
        $this->assertSame(3, PostSnapshot::count());
    }
}
