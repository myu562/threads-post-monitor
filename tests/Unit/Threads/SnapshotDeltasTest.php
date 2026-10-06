<?php

namespace Tests\Unit\Threads;

use App\Models\PostSnapshot;
use App\Threads\SnapshotDeltas;
use Carbon\CarbonImmutable;
use Tests\TestCase; // app container needed for model casts (no database is touched)

class SnapshotDeltasTest extends TestCase
{
    private function snap(string $at, array $m = []): PostSnapshot
    {
        $s = new PostSnapshot;
        $s->forceFill(array_merge([
            'captured_at' => CarbonImmutable::parse($at, 'UTC'),
            'views' => 100, 'likes' => 10, 'replies' => 3, 'reposts' => 2, 'quotes' => 1, 'shares' => 0,
        ], $m));

        return $s;
    }

    public function test_empty_input_gives_no_rows(): void
    {
        $this->assertSame([], SnapshotDeltas::for([]));
    }

    public function test_first_row_has_no_interval_delta_or_rate(): void
    {
        $rows = SnapshotDeltas::for([$this->snap('2026-10-05 18:00')]);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['interval_hours']);
        foreach (['views', 'likes', 'replies', 'reposts', 'quotes', 'shares'] as $m) {
            $this->assertArrayHasKey($m, $rows[0]['delta']);
            $this->assertNull($rows[0]['delta'][$m]);
            $this->assertNull($rows[0]['per_hour'][$m]);
        }
    }

    public function test_one_hour_increments_for_all_six_metrics(): void
    {
        $a = $this->snap('2026-10-05 18:00');
        $b = $this->snap('2026-10-05 19:00', ['views' => 160, 'likes' => 12, 'replies' => 9, 'reposts' => 2, 'quotes' => 4, 'shares' => 5]);
        $rows = SnapshotDeltas::for([$a, $b]);

        $this->assertSame($b, $rows[1]['snapshot']);
        $this->assertEquals(1.0, $rows[1]['interval_hours']);
        $this->assertSame(['views' => 60, 'likes' => 2, 'replies' => 6, 'reposts' => 0, 'quotes' => 3, 'shares' => 5], $rows[1]['delta']);
        $this->assertSame(['views' => 60, 'likes' => 2, 'replies' => 6, 'reposts' => 0, 'quotes' => 3, 'shares' => 5], $rows[1]['per_hour']);
    }

    public function test_null_on_either_side_gives_null_delta_not_zero(): void
    {
        $rows = SnapshotDeltas::for([
            $this->snap('2026-10-05 18:00', ['views' => null, 'shares' => 4]),
            $this->snap('2026-10-05 19:00', ['views' => 500, 'shares' => null]),
            $this->snap('2026-10-05 20:00', ['views' => 650, 'shares' => 7]),
        ]);

        $this->assertNull($rows[1]['delta']['views']);   // previous was null
        $this->assertNull($rows[1]['per_hour']['views']);
        $this->assertNull($rows[1]['delta']['shares']);  // current is null
        $this->assertSame(150, $rows[2]['delta']['views']);
        $this->assertNull($rows[2]['delta']['shares']);  // previous (row 1) was null: no skipping back
        $this->assertSame(0, $rows[1]['delta']['likes']);
    }

    public function test_negative_increment_is_kept(): void
    {
        $rows = SnapshotDeltas::for([
            $this->snap('2026-10-05 18:00', ['likes' => 40]),
            $this->snap('2026-10-05 19:00', ['likes' => 37]),
        ]);

        $this->assertSame(-3, $rows[1]['delta']['likes']);
        $this->assertSame(-3, $rows[1]['per_hour']['likes']);
    }

    public function test_interval_under_half_an_hour_has_delta_but_no_rate(): void
    {
        $rows = SnapshotDeltas::for([
            $this->snap('2026-10-05 18:00'),
            $this->snap('2026-10-05 18:20', ['views' => 130]),
        ]);

        $this->assertEquals(0.33, $rows[1]['interval_hours']);
        $this->assertSame(30, $rows[1]['delta']['views']);
        $this->assertNull($rows[1]['per_hour']['views']);
    }

    public function test_exactly_half_an_hour_gets_a_rate(): void
    {
        $rows = SnapshotDeltas::for([
            $this->snap('2026-10-05 18:00'),
            $this->snap('2026-10-05 18:30', ['views' => 130]),
        ]);

        $this->assertSame(60, $rows[1]['per_hour']['views']);
    }

    public function test_three_hour_interval_is_converted_per_hour_and_rounded(): void
    {
        $rows = SnapshotDeltas::for([
            $this->snap('2026-10-06 00:00', ['views' => 1000, 'replies' => 10, 'likes' => 50]),
            $this->snap('2026-10-06 03:00', ['views' => 1300, 'replies' => 14, 'likes' => 45]),
        ]);

        $this->assertEquals(3.0, $rows[1]['interval_hours']);
        $this->assertSame(300, $rows[1]['delta']['views']);
        $this->assertSame(100, $rows[1]['per_hour']['views']);
        $this->assertSame(4, $rows[1]['delta']['replies']);
        $this->assertSame(1, $rows[1]['per_hour']['replies']);  // 1.33 → 1
        $this->assertSame(-5, $rows[1]['delta']['likes']);
        $this->assertSame(-2, $rows[1]['per_hour']['likes']);   // -1.67 → -2
    }

    public function test_format_and_class_never_rely_on_color_alone(): void
    {
        $this->assertSame('+6', SnapshotDeltas::format(6));
        $this->assertSame('+1,234', SnapshotDeltas::format(1234));
        $this->assertSame("\u{2212}3", SnapshotDeltas::format(-3));
        $this->assertSame('±0', SnapshotDeltas::format(0));
        $this->assertSame('—', SnapshotDeltas::format(null));

        $this->assertSame('delta-up', SnapshotDeltas::cssClass(6));
        $this->assertSame('delta-down', SnapshotDeltas::cssClass(-3));
        $this->assertSame('delta-flat', SnapshotDeltas::cssClass(0));
        $this->assertSame('delta-flat', SnapshotDeltas::cssClass(null));
    }
}
