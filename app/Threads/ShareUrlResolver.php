<?php

namespace App\Threads;

use App\Threads\Exceptions\ShareResolveException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Turns a Threads share code (from https://www.threads.com/share/{code}/) into a post URL.
 *
 * Exactly one GET, no redirects followed, no cookies:
 *  - 3xx: the Location header is the post URL. Observed with the Googlebot UA
 *    (config threads.user_agent): 302 → https://www.threads.com/@{username}/post/{shortcode}?xmt=…&slof=1
 *  - 200: fall back to og:url, then <link rel="canonical">. UNVERIFIED (never observed): with a desktop
 *    browser UA Threads answers 200 and redirects in JS; we assume the page still carries the post
 *    URL in og:url / canonical.
 * Whatever comes back must pass ThreadsUrl::parse(), otherwise ShareResolveException.
 *
 * The returned URL may still contain tracking query parameters (xmt identifies who shared it);
 * callers must store a cleaned URL, never this value or the share link itself.
 */
class ShareUrlResolver
{
    private const BASE = 'https://www.threads.com';

    private const FAILED = 'errors.share.failed';

    public function resolve(string $shareCode): string
    {
        if (! preg_match('/^'.ThreadsUrl::SHARE_CODE_PATTERN.'$/', $shareCode)) {
            throw new ShareResolveException('errors.share.invalid');
        }

        try {
            $response = Http::withUserAgent((string) config('threads.user_agent'))
                ->timeout((int) config('threads.timeout', 30))
                ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
                ->withoutRedirecting()
                ->get(self::BASE.'/share/'.$shareCode.'/');
        } catch (ConnectionException) {
            // Never surface the exception text: it can echo the requested URL.
            throw new ShareResolveException('errors.share.unreachable');
        } catch (Throwable) {
            throw new ShareResolveException(self::FAILED);
        }

        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            $candidate = $response->header('Location');
        } elseif ($status === 200) {
            $candidate = $this->pageUrl((string) $response->body());
        } elseif ($status === 404 || $status === 410) {
            throw new ShareResolveException('errors.share.gone');
        } else {
            throw new ShareResolveException(self::FAILED);
        }

        $candidate = trim((string) $candidate);
        if ($candidate !== '' && str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')) {
            $candidate = self::BASE.$candidate;
        }

        if ($candidate === '' || ThreadsUrl::parse($candidate) === null) {
            throw new ShareResolveException(self::FAILED);
        }

        return $candidate;
    }

    /** og:url, then <link rel="canonical">, from a 200 page (unverified path, see class doc). */
    private function pageUrl(string $html): ?string
    {
        $patterns = [
            '#<meta\s[^>]*property=["\']og:url["\'][^>]*content=["\']([^"\']+)["\']#i',
            '#<meta\s[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:url["\']#i',
            '#<link\s[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']#i',
            '#<link\s[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\']#i',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $html, $m)) {
                $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (ThreadsUrl::parse($url) !== null) {
                    return $url;
                }
            }
        }

        return null;
    }
}
