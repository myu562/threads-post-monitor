<?php

namespace App\Threads;

/**
 * Parses user-supplied Threads post URLs into a shortcode.
 *
 * Accepted (docs/HOW-IT-WORKS.md):
 *   https://www.threads.net/@{username}/post/{shortcode}
 *   https://www.threads.com/@{username}/post/{shortcode}
 *   https://threads.com/t/{shortcode}
 * with or without "www.", an optional query string / fragment, and a trailing slash.
 * The scheme may be omitted. Anything else returns null.
 *
 * Share links (https://www.threads.com/share/{code}/) are NOT post URLs: parse() returns null for
 * them, parseShare() extracts the code, and ShareUrlResolver turns it into a post URL.
 */
final class ThreadsUrl
{
    private const HOSTS = ['threads.net', 'www.threads.net', 'threads.com', 'www.threads.com'];

    public const SHARE_CODE_PATTERN = '[A-Za-z0-9_-]{4,64}';

    /**
     * @return array{shortcode: string, username: ?string, canonical_url: string}|null
     */
    public static function parse(string $url): ?array
    {
        $parts = self::threadsParts($url);
        if ($parts === null) {
            return null;
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if (preg_match('#^/@([A-Za-z0-9_.]{1,64})/post/('.Shortcode::PATTERN.')$#', $path, $m)) {
            $username = $m[1];
            $shortcode = $m[2];
        } elseif (preg_match('#^/t/('.Shortcode::PATTERN.')$#', $path, $m)) {
            $username = null;
            $shortcode = $m[1];
        } else {
            return null;
        }

        return [
            'shortcode' => $shortcode,
            'username' => $username,
            'canonical_url' => self::canonical($shortcode),
        ];
    }

    /** Share code from https?://(www.)threads.(com|net)/share/{code}[/][?query], or null. */
    public static function parseShare(string $url): ?string
    {
        $parts = self::threadsParts($url);
        if ($parts === null) {
            return null;
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if (preg_match('#^/share/('.self::SHARE_CODE_PATTERN.')$#', $path, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * The URL stored for a post: no query, no fragment, no share code.
     * https://www.threads.com/@{username}/post/{shortcode}, or canonical() without a username.
     */
    public static function clean(string $shortcode, ?string $username): string
    {
        return $username === null || $username === ''
            ? self::canonical($shortcode)
            : 'https://www.threads.com/@'.$username.'/post/'.$shortcode;
    }

    /** The URL the fetcher requests and the app stores as canonical. */
    public static function canonical(string $shortcode): string
    {
        return 'https://www.threads.com/t/'.$shortcode;
    }

    /**
     * parse_url() parts of an http(s) URL on a Threads host (no userinfo/port), else null.
     *
     * @return array<string, mixed>|null
     */
    private static function threadsParts(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        if (! in_array(strtolower($parts['host']), self::HOSTS, true)) {
            return null;
        }

        return $parts;
    }
}
