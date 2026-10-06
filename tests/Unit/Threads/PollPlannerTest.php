<?php

namespace Tests\Unit\Threads;

use App\Threads\PollPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PollPlannerTest extends TestCase
{
    private CarbonImmutable $pub;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'threads.track_hours' => 72,
            'threads.poll_bands' => [[0, 24, 60], [24, 72, 180]],
        ]);
        $this->pub = CarbonImmutable::parse('2026-10-03 04:00:00', 'UTC');
    }

    private function next(int $minutesAfterPublish): int
    {
        $at = (new PollPlanner)->nextPollAt($this->pub, $this->pub->addMinutes($minutesAfterPublish));

        return (int) (($at->getTimestamp() - $this->pub->getTimestamp()) / 60);
    }

    /** @return array<string, array{int, int}> minutes after publish => expected next poll (minutes after publish) */
    public static function cases(): array
    {
        return [
            'at publish' => [0, 60],
            'mid first slot' => [30, 60],
            'exactly on grid point' => [60, 120],
            'just after grid point' => [61, 120],
            'just before band boundary' => [23 * 60 + 30, 24 * 60],
            'one second-ish before 24h' => [24 * 60 - 1, 24 * 60],
            'exactly at 24h band boundary' => [24 * 60, 27 * 60],
            'inside second band' => [25 * 60, 27 * 60],
            'exactly 27h' => [27 * 60, 30 * 60],
            '69h is last grid before end' => [69 * 60, 72 * 60],
            'one minute before 72h' => [72 * 60 - 1, 72 * 60],
            'exactly 72h' => [72 * 60, 72 * 60],
            'past 72h' => [100 * 60, 72 * 60],
        ];
    }

    #[DataProvider('cases')]
    public function test_band_boundaries(int $now, int $expected): void
    {
        $this->assertSame($expected, $this->next($now));
    }

    public function test_now_before_publish_is_treated_as_just_published(): void
    {
        $at = (new PollPlanner)->nextPollAt($this->pub, $this->pub->subMinutes(10));
        $this->assertTrue($at->equalTo($this->pub->addMinutes(60)));
    }

    public function test_walking_the_schedule_ends_exactly_at_track_until(): void
    {
        $planner = new PollPlanner;
        $trackUntil = $this->pub->addHours(72);
        $now = $this->pub;
        $polls = [];
        for ($guard = 0; $guard < 500; $guard++) {
            $next = $planner->nextPollAt($this->pub, $now);
            $this->assertTrue($next->greaterThan($now) || $next->equalTo($trackUntil));
            $this->assertTrue($next->lessThanOrEqualTo($trackUntil));
            $polls[] = $next;
            if ($next->equalTo($trackUntil)) {
                break;
            }
            $now = $next;
        }

        $this->assertTrue(end($polls)->equalTo($trackUntil));
        $this->assertCount(24 + 16, $polls); // 24 hourly + 16 three-hourly, last one = 72h
    }

    public function test_late_run_does_not_drift_the_grid(): void
    {
        // Cron ran 4 minutes late at 2h04m; next is still the 3h grid point.
        $this->assertSame(180, $this->next(124));
    }

    public function test_result_capped_at_track_until_when_bands_exceed_it(): void
    {
        config(['threads.track_hours' => 48]);
        // 46h + 180 min would be 49h > 48h.
        $this->assertSame(48 * 60, $this->next(46 * 60));
    }

    public function test_uncovered_time_goes_straight_to_track_until(): void
    {
        config(['threads.poll_bands' => [[0, 24, 60]]]);
        $this->assertSame(72 * 60, $this->next(30 * 60));
        // Inside the band, the band end is the cap.
        $this->assertSame(24 * 60, $this->next(23 * 60 + 1));
    }

    /** @return list<CarbonImmutable> every poll from publish to trackUntil */
    private function walk(): array
    {
        $planner = new PollPlanner;
        $trackUntil = $planner->trackUntil($this->pub);
        $now = $this->pub;
        $polls = [];
        for ($guard = 0; $guard < 1000; $guard++) {
            $next = $planner->nextPollAt($this->pub, $now);
            $polls[] = $next;
            if ($next->equalTo($trackUntil)) {
                break;
            }
            $now = $next;
        }

        return $polls;
    }

    public function test_config_default_is_hourly_for_72_hours(): void
    {
        // Same default as the folder version (PollPlanner.DefaultBands). Holds while
        // THREADS_POLL_BANDS is not set in the environment running the tests.
        $this->assertSame([[0, 72, 60]], PollPlanner::DEFAULT_BANDS);
        $this->assertSame(PollPlanner::DEFAULT_BANDS, (require base_path('config/threads.php'))['poll_bands']);
    }

    public function test_default_schedule_is_72_hourly_polls_ending_at_72h(): void
    {
        config(['threads.poll_bands' => PollPlanner::DEFAULT_BANDS]);
        $polls = $this->walk();

        $this->assertCount(72, $polls);
        foreach ($polls as $i => $at) {
            $this->assertTrue($at->equalTo($this->pub->addHours($i + 1)), "poll #{$i}");
        }
        $this->assertTrue(end($polls)->equalTo($this->pub->addHours(72)));
        $this->assertSame(19 * 60, $this->next(18 * 60)); // no gap after 24h any more
    }

    public function test_empty_config_uses_the_default(): void
    {
        foreach ([null, '', '   ', []] as $value) {
            config(['threads.poll_bands' => $value]);
            $this->assertSame(PollPlanner::DEFAULT_BANDS, (new PollPlanner)->bands());
        }
    }

    /**
     * Same cases as standalone/tests/ThreadsMonitor.Tests/ScheduleLogicTests.cs (PollBandsSpecTests).
     *
     * @return array<string, array{string, list<array{int, int, int}>|null}>
     */
    public static function specs(): array
    {
        return [
            'hourly then 3-hourly' => ['0-24:60,24-72:180', [[0, 24, 60], [24, 72, 180]]],
            'single band' => ['0-72:60', [[0, 72, 60]]],
            'spaces allowed' => [' 0 - 24 : 60 , 24 - 72 : 180 ', [[0, 24, 60], [24, 72, 180]]],
            'gap allowed' => ['0-24:60,48-72:120', [[0, 24, 60], [48, 72, 120]]],
            'minimum interval' => ['0-72:5', [[0, 72, 5]]],
            'not a band' => ['abc', null],
            'missing minutes' => ['0-24', null],
            'trailing comma' => ['0-24:60,', null],
            'semicolon separator' => ['0-24:60;24-72:180', null],
            'to before from' => ['24-0:60', null],
            'to equals from' => ['5-5:60', null],
            'negative hour' => ['-1-24:60', null],
            'fractional hour' => ['0.5-24:60', null],
            'interval below 5' => ['0-24:4', null],
            'interval zero' => ['0-24:0', null],
            'overlap' => ['0-24:60,12-72:180', null],
            'out of order' => ['24-72:180,0-24:60', null],
            'one bad band spoils all' => ['0-24:60,24-72:x', null],
        ];
    }

    /** @param list<array{int, int, int}>|null $expected */
    #[DataProvider('specs')]
    public function test_parse_bands(string $spec, ?array $expected): void
    {
        $this->assertSame($expected, PollPlanner::parseBands($spec));
    }

    public function test_valid_string_config_is_used(): void
    {
        Log::spy();
        config(['threads.poll_bands' => '0-24:60,24-72:180']);
        $this->assertSame(27 * 60, $this->next(24 * 60));
        $this->assertCount(24 + 16, $this->walk());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_invalid_string_config_warns_and_uses_the_default(): void
    {
        Log::spy();
        config(['threads.poll_bands' => '0-24:60,12-72:180 (invalid test value)']);
        $this->assertSame(25 * 60, $this->next(24 * 60));
        $this->assertCount(72, $this->walk());
        Log::shouldHaveReceived('warning')->once(); // once per process, not on every call
    }

    public function test_track_until_is_publish_plus_track_hours(): void
    {
        $this->assertTrue((new PollPlanner)->trackUntil($this->pub)->equalTo($this->pub->addHours(72)));
    }
}
