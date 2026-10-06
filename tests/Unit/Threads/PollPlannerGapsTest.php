<?php

namespace Tests\Unit\Threads;

use App\Threads\PollPlanner;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RA-F2: time not covered by any band. Same semantics and the same cases as the folder version
 * (standalone/): in a gap the next poll is the start of the next band; before a first band that
 * starts after 0 nothing is fetched until it starts; after the last band the next poll is
 * track_until. (The first fetch when a post is added is immediate and not planned here.)
 */
class PollPlannerGapsTest extends TestCase
{
    private CarbonImmutable $pub;

    protected function setUp(): void
    {
        parent::setUp();
        config(['threads.track_hours' => 72]);
        $this->pub = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
    }

    /** Next poll, in minutes after publish, for "now" = $minutes after publish. */
    private function next(string $spec, int $minutes): int
    {
        config(['threads.poll_bands' => $spec]);
        $at = (new PollPlanner)->nextPollAt($this->pub, $this->pub->addMinutes($minutes));

        return intdiv($at->getTimestamp() - $this->pub->getTimestamp(), 60);
    }

    /** @return list<float> every planned poll from publish to track_until, in hours after publish */
    private function walk(string $spec): array
    {
        config(['threads.poll_bands' => $spec]);
        $planner = new PollPlanner;
        $end = $planner->trackUntil($this->pub);
        $now = $this->pub;
        $out = [];
        for ($i = 0; $i < 500; $i++) {
            $next = $planner->nextPollAt($this->pub, $now);
            $this->assertTrue($next->greaterThan($now) || $next->equalTo($end));
            $out[] = ($next->getTimestamp() - $this->pub->getTimestamp()) / 3600;
            if ($next->greaterThanOrEqualTo($end)) {
                break;
            }
            $now = $next;
        }

        return $out;
    }

    /** @return array<string, array{string, int, int}> spec, minutes after publish, expected next poll (minutes) */
    public static function cases(): array
    {
        return [
            // Gap between bands: the next poll is the start of the next band.
            'gap: at 24h' => ['0-24:60,48-72:60', 24 * 60, 48 * 60],
            'gap: inside' => ['0-24:60,48-72:60', 30 * 60, 48 * 60],
            'gap: one minute before the next band' => ['0-24:60,48-72:60', 48 * 60 - 1, 48 * 60],
            'gap: next band starts normally' => ['0-24:60,48-72:60', 48 * 60, 49 * 60],
            'gap: last poll of the first band' => ['0-24:60,48-72:60', 23 * 60, 24 * 60],
            // First band starts after 0: nothing before it.
            'late first band: at publish' => ['1-72:60', 0, 60],
            'late first band: 30 min' => ['1-72:60', 30, 60],
            'late first band: at 1h' => ['1-72:60', 60, 120],
            // Two bands with a long gap.
            'half-hourly: at publish' => ['0-6:30,24-72:60', 0, 30],
            'half-hourly: end of first band' => ['0-6:30,24-72:60', 6 * 60, 24 * 60],
            'half-hourly: in the gap' => ['0-6:30,24-72:60', 12 * 60, 24 * 60],
            'half-hourly: second band' => ['0-6:30,24-72:60', 24 * 60, 25 * 60],
            // After the last band: straight to track_until.
            'after last band' => ['0-24:60', 30 * 60, 72 * 60],
        ];
    }

    #[DataProvider('cases')]
    public function test_next_poll(string $spec, int $now, int $expected): void
    {
        $this->assertSame($expected, $this->next($spec, $now));
    }

    public function test_gap_schedule_0_24_and_48_72(): void
    {
        $expected = array_merge(range(1, 24), range(48, 72));
        $this->assertSame($expected, array_map('intval', $this->walk('0-24:60,48-72:60')));
        $this->assertCount(49, $expected);
    }

    public function test_late_first_band_1_72(): void
    {
        $this->assertSame(range(1, 72), array_map('intval', $this->walk('1-72:60')));
    }

    public function test_half_hourly_then_hourly_with_gap(): void
    {
        $expected = array_merge(array_map(fn ($i) => $i / 2, range(1, 12)), range(24, 72));
        $this->assertEquals($expected, $this->walk('0-6:30,24-72:60'));
    }

    public function test_after_the_last_band_only_track_until_remains(): void
    {
        $this->assertSame(array_merge(range(1, 24), [72]), array_map('intval', $this->walk('0-24:60')));
    }

    public function test_next_band_start_beyond_track_until_is_capped(): void
    {
        config(['threads.track_hours' => 36]);
        $this->assertSame(36 * 60, $this->next('0-24:60,48-72:60', 30 * 60));
    }

    public function test_array_bands_out_of_order_are_sorted(): void
    {
        config(['threads.poll_bands' => [[48, 72, 60], [0, 24, 60]]]);
        $at = (new PollPlanner)->nextPollAt($this->pub, $this->pub->addHours(30));
        $this->assertTrue($at->equalTo($this->pub->addHours(48)));
    }
}
