<?php

namespace App\Threads;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Threads/Instagram shortcode decoding (decoded pk matches the page pk, docs/HOW-IT-WORKS.md §7).
 *
 * pk = Σ index(c)·64^k over a URL-safe base64 alphabet, most significant char first.
 * created_ms = (pk >> 23) + IG epoch.
 *
 * createdAt() is the id creation time, NOT necessarily the publish time (one sample was 8.6 s
 * earlier than taken_at). Use it only as a fallback when the page's taken_at is unavailable.
 * pk can exceed PHP_INT_MAX range comfortably only as a string, so bcmath is used throughout.
 */
final class Shortcode
{
    public const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    public const IG_EPOCH_MS = 1314220021721;

    /** Regex fragment (no delimiters) for a syntactically valid shortcode. */
    public const PATTERN = '[A-Za-z0-9_-]{5,64}';

    public static function isValid(string $code): bool
    {
        return (bool) preg_match('/^'.self::PATTERN.'$/', $code);
    }

    /** @return string decimal pk */
    public static function toPk(string $code): string
    {
        if ($code === '') {
            throw new InvalidArgumentException('Empty shortcode.');
        }
        $n = '0';
        foreach (str_split($code) as $ch) {
            $i = strpos(self::ALPHABET, $ch);
            if ($i === false) {
                throw new InvalidArgumentException('Invalid shortcode character.');
            }
            $n = bcadd(bcmul($n, '64', 0), (string) $i, 0);
        }

        return $n;
    }

    public static function createdAt(string $code): CarbonImmutable
    {
        $pk = self::toPk($code);
        $ms = bcadd(bcdiv($pk, bcpow('2', '23', 0), 0), (string) self::IG_EPOCH_MS, 0);

        return CarbonImmutable::createFromTimestampMsUTC($ms)->setTimezone('UTC');
    }
}
