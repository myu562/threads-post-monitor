<?php

use App\Threads\PollPlanner;

return [
    /*
    | User-Agent for logged-out public post page fetches. Over plain HTTP only a
    | Googlebot UA gets server-side rendered post JSON.
    | See docs/HOW-IT-WORKS.md §3 and §11 (risk: may stop working at any time).
    */
    'user_agent' => env('THREADS_USER_AGENT', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'),

    // HTTP timeout in seconds for a single page fetch.
    'timeout' => (int) env('THREADS_TIMEOUT', 30),

    // How long after publish a post is tracked.
    'track_hours' => (int) env('THREADS_TRACK_HOURS', 72),

    /*
    | Polling bands: [from_hour, to_hour, interval_minutes], relative to publish time.
    | Default: every 60 minutes from 0 to 72 hours. THREADS_POLL_BANDS overrides it with
    | "from-to:minutes" bands separated by commas, e.g. "0-24:60,24-72:180" (hourly for the
    | first day, then every 3 hours: fewer fetches, lower risk of being blocked). An invalid
    | value is ignored with a warning in the log. See App\Threads\PollPlanner::parseBands().
    */
    'poll_bands' => env('THREADS_POLL_BANDS') ?: PollPlanner::DEFAULT_BANDS,

    // Mark a post failed after this many consecutive fetch failures.
    'max_consecutive_failures' => (int) env('THREADS_MAX_CONSECUTIVE_FAILURES', 5),

    // After a failed fetch (not gone), retry after this many minutes (capped at track_until).
    'retry_minutes' => (int) env('THREADS_RETRY_MINUTES', 15),

    // Sleep between two consecutive page fetches in one run.
    'politeness_sleep_ms' => (int) env('THREADS_POLITENESS_SLEEP_MS', 3000),

    // post_snapshots.raw_json older than this is pruned.
    'snapshot_raw_retention_days' => (int) env('THREADS_SNAPSHOT_RAW_RETENTION_DAYS', 7),

    // Storage is UTC; convert to this zone only when displaying.
    'display_timezone' => env('THREADS_DISPLAY_TIMEZONE', 'Asia/Taipei'),

    /*
    | Credit line at the bottom of every web page. Self-hosters can turn it off
    | (THREADS_FOOTER_ENABLED=false) or show their own text and link. The url
    | must be http(s); anything else is ignored and only the text is shown.
    */
    'footer' => [
        'enabled' => env('THREADS_FOOTER_ENABLED', true),
        'text' => env('THREADS_FOOTER_TEXT', 'Powered by Sony Chou'),
        'url' => env('THREADS_FOOTER_URL', 'https://www.facebook.com/geoffrey.chou'),
    ],
];
