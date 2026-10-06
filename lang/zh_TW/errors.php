<?php

// Error messages shown to users. "stored" renders tracked_posts.last_error, which is saved as
// "code|{json params}" (App\Support\StoredMessage). Keep the keys identical to lang/en/errors.php.
return [
    'stored' => [
        'fetch' => [
            'gone' => 'HTTP :status：找不到貼文。',
            'gone_unverified' => '頁面已不再指向這篇貼文（推定已刪除，這個判斷尚未實測）。',
            'rate_limited' => 'HTTP :status：Threads 暫時限制了請求頻率。',
            'blocked' => '頁面沒有貼文資料，也沒有 og:description（可能是登入牆或驗證頁）。',
            'parse_failed' => '頁面有載入，但頁面的 JSON 裡找不到貼文。',
            'network_error' => '連不上 Threads（網路錯誤）。',
            'http_error' => '非預期的 HTTP :status。',
            'request_failed' => ':class：請求失敗，詳見伺服器 log',
            'invalid_shortcode' => '貼文代碼不正確。',
        ],
        'poll' => [
            'no_publish_time' => '無法得知發布時間：頁面沒有 taken_at，也無法從貼文代碼推算。',
        ],
        'exception' => ':class：內部錯誤，詳見伺服器 log',
    ],

    'share' => [
        'invalid' => '分享連結的格式不正確。',
        'unreachable' => '連不上 Threads，暫時無法還原分享連結，請稍後再試。',
        'gone' => '這個分享連結已失效或貼文已刪除。',
        'failed' => '無法還原這個分享連結，請改貼貼文本身的網址（像 https://www.threads.com/@帳號/post/代碼）。',
    ],

    'url' => [
        'unrecognized' => '無法辨識的 Threads 貼文網址，請貼上像 https://www.threads.com/@帳號/post/代碼 這樣的網址。',
    ],
];
