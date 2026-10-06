<?php

namespace App\Threads;

use App\Models\PostSnapshot;
use App\Models\TrackedPost;

/**
 * Per-snapshot increments, for the "hourly" breakdown under each post.
 *
 * For each snapshot (oldest first) it reports the gap to the previous snapshot and, per metric,
 * the change since then. A change is only computed when both readings exist (null stays null —
 * "not available" is never treated as 0). The per-hour rate is only given when the gap is at
 * least half an hour: manual re-polls and retries can be minutes apart, and dividing by those
 * would produce meaningless spikes.
 */
final class SnapshotDeltas
{
    public const MIN_INTERVAL_HOURS = 0.5;

    /**
     * @param  iterable<PostSnapshot>  $snapshotsAscending
     * @return list<array{snapshot: PostSnapshot, interval_hours: ?float, delta: array<string, ?int>, per_hour: array<string, ?int>}>
     */
    public static function for(iterable $snapshotsAscending): array
    {
        $rows = [];
        $prev = null;
        foreach ($snapshotsAscending as $snap) {
            $interval = null;
            if ($prev !== null && $prev->captured_at !== null && $snap->captured_at !== null) {
                $interval = round(($snap->captured_at->getTimestamp() - $prev->captured_at->getTimestamp()) / 3600, 2);
            }

            $delta = [];
            $perHour = [];
            foreach (TrackedPost::METRICS as $m) {
                $d = ($prev !== null && $prev->{$m} !== null && $snap->{$m} !== null)
                    ? (int) $snap->{$m} - (int) $prev->{$m}
                    : null;
                $delta[$m] = $d;
                $perHour[$m] = ($d !== null && $interval !== null && $interval >= self::MIN_INTERVAL_HOURS)
                    ? (int) round($d / $interval)
                    : null;
            }

            $rows[] = ['snapshot' => $snap, 'interval_hours' => $interval, 'delta' => $delta, 'per_hour' => $perHour];
            $prev = $snap;
        }

        return $rows;
    }

    /** "+6", "−3" (U+2212), "±0", or "—" when unknown. Signs make the direction readable without color. */
    public static function format(?int $delta): string
    {
        return match (true) {
            $delta === null => '—',
            $delta > 0 => '+'.number_format($delta),
            $delta < 0 => "\u{2212}".number_format(abs($delta)),
            default => '±0',
        };
    }

    /** CSS class for the change: up (success), down (danger), flat or unknown (muted). */
    public static function cssClass(?int $delta): string
    {
        return match (true) {
            $delta === null, $delta === 0 => 'delta-flat',
            $delta > 0 => 'delta-up',
            default => 'delta-down',
        };
    }

    /** Short screen-reader label for the direction. */
    public static function label(?int $delta): string
    {
        return match (true) {
            $delta === null => __('posts.delta.unknown'),
            $delta > 0 => __('posts.delta.up'),
            $delta < 0 => __('posts.delta.down'),
            default => __('posts.delta.flat'),
        };
    }
}
