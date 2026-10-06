<?php

namespace App\Threads;

use App\Threads\Exceptions\ParseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches a single logged-out public post page and classifies the outcome.
 *
 * One GET per call; no cookies, no tokens. Politeness pacing between calls is the caller's job
 * (config threads.politeness_sleep_ms).
 *
 * Classification:
 *  - 404 / 410                                   → gone
 *  - 429, or a 200 page with neither post JSON
 *    nor og:description (login wall / challenge) → blocked
 *  - 200 with og:description but no post JSON   → parse_failed
 *    (except the "gone" heuristic below)
 *  - connection exception                       → network_error
 *  - anything else                              → http_error
 */
class PublicPageFetcher
{
    private const EXCERPT_LIMIT = 500;

    public function __construct(private readonly PostPageParser $parser) {}

    public function fetch(string $shortcode): FetchResult
    {
        if (! Shortcode::isValid($shortcode)) {
            return FetchResult::failure(FetchResult::HTTP_ERROR, null, 'Invalid shortcode.', null, 'fetch.invalid_shortcode');
        }

        $url = ThreadsUrl::canonical($shortcode);

        try {
            $response = Http::withUserAgent((string) config('threads.user_agent'))
                ->timeout((int) config('threads.timeout', 30))
                ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
                ->get($url);
        } catch (ConnectionException $e) {
            return FetchResult::failure(FetchResult::NETWORK_ERROR, null, self::oneLine('Connection failed: '.$e->getMessage()), null, 'fetch.network_error');
        } catch (Throwable $e) {
            return FetchResult::failure(FetchResult::HTTP_ERROR, null, self::oneLine(class_basename($e).': '.$e->getMessage()), null,
                'fetch.request_failed', ['class' => class_basename($e)]);
        }

        $code = $response->status();
        $body = (string) $response->body();

        if ($code === 404 || $code === 410) {
            return FetchResult::failure(FetchResult::GONE, $code, "HTTP {$code}: post not found.", $this->excerpt($body), 'fetch.gone', ['status' => $code]);
        }
        if ($code === 429) {
            return FetchResult::failure(FetchResult::BLOCKED, $code, 'HTTP 429: rate limited.', $this->excerpt($body), 'fetch.rate_limited', ['status' => $code]);
        }
        if ($code !== 200) {
            return FetchResult::failure(FetchResult::HTTP_ERROR, $code, "Unexpected HTTP {$code}.", $this->excerpt($body), 'fetch.http_error', ['status' => $code]);
        }

        try {
            $data = $this->parser->parse($body, $shortcode);
        } catch (ParseException $e) {
            return $this->classifyMissingPost($body, $shortcode, $code);
        }

        return FetchResult::success($code, $data, json_encode($data->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null);
    }

    private function classifyMissingPost(string $body, string $shortcode, int $code): FetchResult
    {
        $ogDescription = $this->parser->ogContent($body, 'description');

        if ($ogDescription === null) {
            // No post JSON and no og:description: login wall, checkpoint or an empty shell page.
            return FetchResult::failure(FetchResult::BLOCKED, $code, 'No post data and no og:description (login wall or challenge page).', $this->excerpt($body), 'fetch.blocked');
        }

        // UNVERIFIED (never observed): we have never observed a deleted post's page. Assumption: Threads
        // serves a generic page whose og:url no longer points at /post/{shortcode} or /t/{shortcode}.
        // Only that combination (og:description present, og:url present but not this post) is
        // treated as gone; everything else stays parse_failed so a parser break is not mistaken for
        // a deletion.
        $ogUrl = $this->parser->ogContent($body, 'url');
        if ($ogUrl !== null && ! preg_match('#/(?:post|t)/'.preg_quote($shortcode, '#').'(?:[/?\#]|$)#', $ogUrl)) {
            return FetchResult::failure(FetchResult::GONE, $code, 'Page does not reference this post (assumed deleted, unverified heuristic).', $this->excerpt($body), 'fetch.gone_unverified');
        }

        return FetchResult::failure(FetchResult::PARSE_FAILED, $code, 'Page loaded but post object not found in page JSON.', $this->excerpt($body), 'fetch.parse_failed');
    }

    /** Short, single-line excerpt for debugging: title + og tags, never the full page. */
    private function excerpt(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        $parts = [];
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
            $parts[] = 'title='.html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        foreach (['url', 'title'] as $p) {
            $v = $this->parser->ogContent($body, $p);
            if ($v !== null) {
                $parts[] = "og:{$p}={$v}";
            }
        }
        $parts[] = 'bytes='.strlen($body);

        return Str::limit(self::oneLine(implode(' | ', $parts)), self::EXCERPT_LIMIT);
    }

    private static function oneLine(string $s): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $s)), 300);
    }
}
