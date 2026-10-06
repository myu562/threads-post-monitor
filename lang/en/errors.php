<?php

// Error messages shown to users. "stored" renders tracked_posts.last_error, which is saved as
// "code|{json params}" (App\Support\StoredMessage). Keep the keys identical to lang/zh_TW/errors.php.
return [
    'stored' => [
        'fetch' => [
            'gone' => 'HTTP :status: post not found.',
            'gone_unverified' => 'The page no longer refers to this post (assumed deleted; this check is unverified).',
            'rate_limited' => 'HTTP :status: Threads is rate limiting requests.',
            'blocked' => 'No post data and no og:description on the page (login wall or challenge page).',
            'parse_failed' => 'The page loaded, but the post was not found in its JSON.',
            'network_error' => 'Could not connect to Threads (network error).',
            'http_error' => 'Unexpected HTTP :status.',
            'request_failed' => ':class: request failed, see the server log',
            'invalid_shortcode' => 'Invalid post code.',
        ],
        'poll' => [
            'no_publish_time' => 'Publish time unknown: the page has no taken_at and the post code gives none.',
        ],
        'exception' => ':class: internal error, see the server log',
    ],

    'share' => [
        'invalid' => 'This share link is not in the right format.',
        'unreachable' => 'Could not reach Threads to resolve the share link. Please try again later.',
        'gone' => 'This share link has expired, or the post was deleted.',
        'failed' => 'Could not resolve this share link. Paste the post\'s own URL instead (like https://www.threads.com/@username/post/CODE).',
    ],

    'url' => [
        'unrecognized' => 'This is not a Threads post URL. Paste a URL like https://www.threads.com/@username/post/CODE.',
    ],
];
