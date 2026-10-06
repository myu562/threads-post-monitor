<?php
namespace Tests\Feature;
use App\Threads\PollPlanner;
use Carbon\CarbonImmutable;
use Tests\TestCase;
class RAPollBandsTest extends TestCase
{
    private function seq(string $spec): array
    {
        config(['threads.poll_bands' => $spec, 'threads.track_hours' => 72]);
        $p = new PollPlanner; $pub = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'); $now = $pub; $out = [];
        for ($i = 0; $i < 200; $i++) { $n = $p->nextPollAt($pub, $now); $out[] = $n->diffInMinutes($pub, true) / 60; if ($n >= $pub->addHours(72)) break; $now = $n; }
        return $out;
    }
    public function test_print(): void
    {
        // RA-F2 fixed: gaps and a late first band are not polled; after the last band only track_until.
        $this->assertSame(range(1, 72), $this->ints($this->seq('')));
        $this->assertSame(array_merge(range(1, 24), range(27, 72, 3)), $this->ints($this->seq('0-24:60,24-72:180')));
        $this->assertSame(array_merge(range(1, 24), range(48, 72)), $this->ints($this->seq('0-24:60,48-72:60')));
        $this->assertSame(range(1, 72), $this->ints($this->seq('1-72:60')));
        $this->assertEquals(array_merge(array_map(fn ($i) => $i / 2, range(1, 24)), [72]), $this->seq('0-12:30'));
        $this->assertEquals(array_merge(array_map(fn ($i) => $i / 2, range(1, 12)), range(24, 72)), $this->seq('0-6:30,24-72:60'));
    }

    /** @param list<int|float> $hours @return list<int> */
    private function ints(array $hours): array
    {
        return array_map('intval', $hours);
    }
}
