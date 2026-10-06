<?php

namespace App\Threads;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Decides when a tracked post should be fetched next.
 *
 * Bands come from config('threads.poll_bands') as [from_hour, to_hour, interval_minutes], relative
 * to publish time. The value may also be a string in the THREADS_POLL_BANDS syntax
 * ("from-to:minutes" separated by commas, e.g. "0-24:60,24-72:180"); an invalid string is ignored
 * with a warning and DEFAULT_BANDS is used. Poll times sit on a grid anchored at each band's start (publish + from_hour),
 * so a late run does not drift the schedule. Outside the bands nothing is fetched: before the first
 * band and in a gap between two bands the next poll is the start of the next band, and after the
 * last band it is trackUntil (the first fetch when a post is added still happens right away). The
 * result never exceeds trackUntil (publish + track_hours), and the grid always ends with a poll
 * exactly at trackUntil. The folder version (standalone/, PollPlanner.cs) follows the same rules.
 */
class PollPlanner
{
    /** Every hour from publish until the end of the 72-hour window. */
    public const DEFAULT_BANDS = [[0, 72, 60]];

    public const MIN_INTERVAL_MINUTES = 5;

    /** @var array<string, true> specs already warned about in this process */
    private static array $warned = [];

    /**
     * Parse "from-to:minutes,from-to:minutes,...". Hours are non-negative integers, to > from, bands
     * are in order and do not overlap (gaps are allowed), minutes >= MIN_INTERVAL_MINUTES.
     *
     * @return list<array{int, int, int}>|null null when the spec is invalid
     */
    public static function parseBands(string $spec): ?array
    {
        $bands = [];
        $prevTo = 0;
        foreach (explode(',', $spec) as $part) {
            if (! preg_match('/^\s*(\d{1,6})\s*-\s*(\d{1,6})\s*:\s*(\d{1,6})\s*$/', $part, $m)) {
                return null;
            }
            [$from, $to, $minutes] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($to <= $from || $from < $prevTo || $minutes < self::MIN_INTERVAL_MINUTES) {
                return null;
            }
            $bands[] = [$from, $to, $minutes];
            $prevTo = $to;
        }

        return $bands;
    }

    /** @return list<array{int, int, int}> */
    public function bands(): array
    {
        $configured = config('threads.poll_bands');
        if (is_array($configured) && $configured !== []) {
            return $configured;
        }
        if (! is_string($configured) || trim($configured) === '') {
            return self::DEFAULT_BANDS;
        }

        $parsed = self::parseBands($configured);
        if ($parsed === null) {
            if (! isset(self::$warned[$configured])) {
                self::$warned[$configured] = true;
                Log::warning('threads: THREADS_POLL_BANDS is invalid; using the default schedule', [
                    'value' => mb_substr($configured, 0, 200),
                    'expected' => 'from-to:minutes,... e.g. 0-24:60,24-72:180 (minutes >= '.self::MIN_INTERVAL_MINUTES.')',
                ]);
            }

            return self::DEFAULT_BANDS;
        }

        return $parsed;
    }

    public function trackUntil(CarbonImmutable $publishedAt): CarbonImmutable
    {
        return $publishedAt->addHours((int) config('threads.track_hours', 72));
    }

    public function nextPollAt(CarbonImmutable $publishedAt, CarbonImmutable $now): CarbonImmutable
    {
        $trackUntil = $this->trackUntil($publishedAt);
        if ($now->greaterThanOrEqualTo($trackUntil)) {
            return $trackUntil;
        }

        // Seconds since publish; a publish time in the future (clock skew) is treated as "just published".
        $elapsed = max(0, $now->getTimestamp() - $publishedAt->getTimestamp());

        $bands = array_map(fn ($band) => array_map('intval', array_values($band)), $this->bands());
        usort($bands, fn (array $a, array $b) => $a[0] <=> $b[0]);

        foreach ($bands as [$fromHour, $toHour, $intervalMinutes]) {
            $from = $fromHour * 3600;
            $to = $toHour * 3600;
            $interval = max(1, $intervalMinutes) * 60;

            if ($elapsed < $from) {
                // Before the first band, or in a gap between two bands: no fetch until this band starts.
                return $this->capped($publishedAt->addSeconds($from), $trackUntil);
            }
            if ($elapsed < $to) {
                $next = $from + $interval * (intdiv($elapsed - $from, $interval) + 1);
                $next = min($next, $to); // never skip past the end of the band

                return $this->capped($publishedAt->addSeconds($next), $trackUntil);
            }
        }

        // After the last band: no more fetches until the final poll at trackUntil.
        return $trackUntil;
    }

    private function capped(CarbonImmutable $at, CarbonImmutable $trackUntil): CarbonImmutable
    {
        return $at->greaterThan($trackUntil) ? $trackUntil : $at;
    }
}
