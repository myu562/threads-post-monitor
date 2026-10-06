<?php

namespace App\Threads;

use App\Threads\Exceptions\ParseException;
use Carbon\CarbonImmutable;

/**
 * Extracts post metrics from a logged-out Threads post page (Googlebot-UA SSR HTML).
 *
 * Source map (docs/HOW-IT-WORKS.md §4 and §5):
 *  - post object: inside <script type="application/json"> Relay payloads; located by a recursive
 *    search for the array with code == shortcode AND a taken_at key (array indices shift between
 *    deploys, so no hard-coded path).
 *  - likes  = media.like_count
 *  - replies/reposts/quotes/shares = text_post_app_info.{direct_reply_count, repost_count,
 *    quote_count, reshare_count}; reshare_count is often null and must stay null.
 *  - views  = page-level config "BarcelonaLoggedOutExpansionGating" → view_counts. Matches the
 *    "X views" shown on the web page (6/6), not verified against the official API.
 *  - like_and_view_counts_disabled = author hid counts → likes/views reported as null.
 *  - caption = media.caption.text (caption itself may be null, or lack text) → null.
 */
class PostPageParser
{
    private const SCRIPT_RE = '#<script type="application/json"[^>]*>(.*?)</script>#s';

    private const VIEWS_RE = '#"BarcelonaLoggedOutExpansionGating",\[\],\{[^{}]*?"view_counts":(\d+)#';

    private const OG_RE = '#<meta\s+property="og:%s"\s+content="([^"]*)"#';

    private const MAX_DEPTH = 64;

    /** Threads captions are capped at 500 characters; anything far beyond that is not a caption. */
    private const CAPTION_MAX = 10000;

    /**
     * @throws ParseException when the post object cannot be found
     */
    public function parse(string $html, string $shortcode): PostData
    {
        if (trim($html) === '') {
            throw new ParseException(ParseException::EMPTY_BODY, 'Empty response body.');
        }

        $post = $this->findPostObject($html, $shortcode);
        if ($post === null) {
            throw new ParseException(ParseException::POST_NOT_FOUND, 'Post object for shortcode not found in page JSON.');
        }

        $tpai = is_array($post['text_post_app_info'] ?? null) ? $post['text_post_app_info'] : [];
        $hidden = ($post['like_and_view_counts_disabled'] ?? false) === true;

        $takenAt = self::intOrNull($post['taken_at'] ?? null);
        $pk = $post['pk'] ?? null;
        $username = $post['user']['username'] ?? null;

        return new PostData(
            shortcode: $shortcode,
            pk: (is_string($pk) || is_int($pk)) && $pk !== '' ? (string) $pk : null,
            username: is_string($username) && $username !== '' ? $username : null,
            takenAt: $takenAt !== null ? CarbonImmutable::createFromTimestampUTC($takenAt)->setTimezone('UTC') : null,
            views: $hidden ? null : $this->extractViews($html),
            likes: $hidden ? null : self::intOrNull($post['like_count'] ?? null),
            replies: self::intOrNull($tpai['direct_reply_count'] ?? null),
            reposts: self::intOrNull($tpai['repost_count'] ?? null),
            quotes: self::intOrNull($tpai['quote_count'] ?? null),
            shares: self::intOrNull($tpai['reshare_count'] ?? null),
            countsHidden: $hidden,
            caption: self::caption($post['caption'] ?? null),
        );
    }

    /** @return array<string, mixed>|null */
    public function findPostObject(string $html, string $shortcode): ?array
    {
        if (! preg_match_all(self::SCRIPT_RE, $html, $m)) {
            return null;
        }
        $needle = '"'.$shortcode.'"';
        foreach ($m[1] as $json) {
            if (! str_contains($json, $needle)) {
                continue;
            }
            $data = json_decode($json, true);
            if (! is_array($data)) {
                continue;
            }
            $hit = $this->search($data, $shortcode, 0);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    public function extractViews(string $html): ?int
    {
        return preg_match(self::VIEWS_RE, $html, $m) ? self::intOrNull($m[1]) : null;
    }

    public function ogContent(string $html, string $property): ?string
    {
        $re = sprintf(self::OG_RE, preg_quote($property, '#'));
        if (! preg_match($re, $html, $m)) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param array<mixed> $node */
    private function search(array $node, string $code, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }
        if (($node['code'] ?? null) === $code && array_key_exists('taken_at', $node)) {
            return $node;
        }
        foreach ($node as $child) {
            if (is_array($child) && ($hit = $this->search($child, $code, $depth + 1)) !== null) {
                return $hit;
            }
        }

        return null;
    }

    private static function caption(mixed $caption): ?string
    {
        $text = is_array($caption) ? ($caption['text'] ?? null) : null;
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        return mb_substr($text, 0, self::CAPTION_MAX, 'UTF-8');
    }

    private static function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 0 ? $v : null;
        }
        if (is_string($v) && ctype_digit($v) && strlen($v) <= 18) {
            return (int) $v;
        }

        return null;
    }
}
