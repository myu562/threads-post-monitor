<?php

namespace App\Http;

use App\Models\User;
use App\Services\AdminAccounts;
use App\Support\TextKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The `login` rate limiter: 10 attempts per 10 minutes per email, and 5 attempts per minute per
 * client IP. IPv6 clients are grouped by /64, since one host usually owns a whole /64.
 *
 * The email bucket uses a folded key (see emailKey()), so variants such as "Admin@", "admín@" or
 * full-width letters share one budget. When the email belongs to an account (looked up the way the
 * login does, with the database's own collation), the account id has a budget of its own as well:
 * every spelling MySQL's utf8mb4_unicode_ci maps to that account shares it.
 */
final class LoginThrottle
{
    public const EMAIL_MAX = 10;

    public const EMAIL_DECAY_MINUTES = 10;

    public const IP_MAX_PER_MINUTE = 5;

    public static function register(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? $email : '';

            $limits = [
                Limit::perMinutes(self::EMAIL_DECAY_MINUTES, self::EMAIL_MAX)
                    ->by('login-email|'.hash('sha256', self::emailKey($email))),
                Limit::perMinute(self::IP_MAX_PER_MINUTE)
                    ->by('login-ip|'.self::ipKey($request->ip())),
            ];
            if (($id = self::accountId($email)) !== null) {
                $limits[] = Limit::perMinutes(self::EMAIL_DECAY_MINUTES, self::EMAIL_MAX)->by('login-account|'.$id);
            }

            return $limits;
        });
    }

    /**
     * Compatibility-decomposed, accents removed, lower-cased, all whitespace removed (hashed by the
     * caller), so case, accent, width and spacing variants share one bucket.
     */
    public static function emailKey(string $email): string
    {
        return (string) preg_replace('/\s+/u', '', TextKey::fold($email));
    }

    /** Id of the account this email signs in to (same lookup as the login), or null. */
    public static function accountId(string $email): ?int
    {
        $email = app(AdminAccounts::class)->normalizeEmail($email);
        if ($email === '' || mb_strlen($email) > 255) {
            return null;
        }

        try {
            $id = User::query()->where('email', $email)->value('id');
        } catch (Throwable) {
            return null; // never let the rate limiter turn a database problem into a 500
        }

        return $id === null ? null : (int) $id;
    }

    /** IPv4 as-is (IPv4-mapped IPv6 too); any other IPv6 address truncated to its /64 prefix. */
    public static function ipKey(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'unknown';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        // ::ffff:a.b.c.d is an IPv4 client; truncating it to /64 would put every IPv4 client in one bucket.
        if (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }

        return inet_ntop(substr($bin, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
