<?php

// Output of threads:install and threads:create-admin (language: --lang, then LC_ALL / LC_MESSAGES /
// LANG, otherwise English). Keep the keys identical to lang/en/cli.php.
return [
    'bad_lang' => '不認得的 --lang 值「:value」，請用 zh 或 en。',

    'admin' => [
        'invalid_email' => 'Email 格式不正確。',
        'short_password' => '密碼至少要 :min 個字元。',
        'password_prompt' => '密碼（至少 :min 個字元）',
        'confirm_prompt' => '再輸入一次密碼',
        'mismatch' => '兩次輸入的密碼不一致。',
        'updated' => '已更新 :email 的密碼。',
        'created' => '已建立管理員 :email。',
        'needs_tty' => '需要互動式終端機輸入密碼，或設定 :env。',
    ],

    'install' => [
        'title' => 'Threads Monitor 安裝程式',

        'ext_ok' => '[0/7] PHP 擴充套件齊全（:list）。',
        'ext_missing' => '[0/7] 缺少 PHP 擴充套件：:list',
        'ext_apt' => '      Debian / Ubuntu：sudo apt install -y :packages',
        'ext_fpm' => '      （PHP-FPM 也需要：sudo systemctl restart php:php-fpm。）裝好後再執行一次安裝程式。',

        'env_exists' => '[1/7] .env 已存在，未更動。',
        'env_no_example' => '[1/7] :path 裡沒有 .env，也沒有 .env.example。',
        'env_create_failed' => '[1/7] 無法建立 :path。目錄可以寫入嗎？',
        'env_created_640' => '[1/7] 已從 .env.example 建立 .env（權限 640）。',
        'env_created' => '[1/7] 已從 .env.example 建立 .env。',
        'env_chmod_failed' => '      無法把權限設成 640，請執行：chmod 640 :path',

        'key_read_failed' => '[2/7] 無法讀取 :path。',
        'key_exists' => '[2/7] APP_KEY 已設定，未更動。',
        'key_write_failed' => '[2/7] 無法把 APP_KEY 寫入 :path。檔案可以寫入嗎？',
        'key_generated' => '[2/7] 已產生 APP_KEY（存在 .env，不顯示）。',
        'env_parse_failed' => '[2/7] 無法解析 .env，請修正語法後再執行一次安裝程式。',
        'secure_cookie_failed' => '[2/7] APP_URL 使用 https://，但無法在 :path 加上 SESSION_SECURE_COOKIE=true，請手動加上。',
        'secure_cookie_set' => '[2/7] APP_URL 使用 https://：已在 .env 設定 SESSION_SECURE_COOKIE=true。',

        'db_not_sqlite' => '[3/7] 資料庫連線是 :connection，不需要 SQLite 檔案。',
        'db_memory' => '[3/7] SQLite 在記憶體中執行，不需要檔案。',
        'db_exists' => '[3/7] SQLite 資料庫 :path 已存在，未更動。',
        'db_create_failed' => '[3/7] 無法建立 SQLite 資料庫 :path。',
        'db_created' => '[3/7] 已建立 SQLite 資料庫 :path。',

        'migrate_start' => '[4/7] 建立／更新資料表（migrate --force）…',
        'migrate_failed' => '[4/7] migrate 失敗：:message',
        'migrate_check_db' => '      請檢查 .env 的資料庫設定（DB_*）。',
        'migrate_readonly' => '      目前的使用者無法寫入 SQLite 資料庫 :path（或它所在的目錄）；.env 的設定沒有問題。',
        'migrate_failed_above' => '[4/7] migrate 失敗（訊息見上方）。請檢查 .env 的資料庫設定（DB_*）。',
        'sqlite_reset' => '      這個 SQLite 資料庫還沒有任何帳號，可以放心重來：',

        'admin_later' => '之後可以用這個指令建立：php artisan threads:create-admin <email>',
        'admin_exists' => '[5/7] 已經有管理員帳號，略過。要新增管理員或重設密碼：php artisan threads:create-admin <email>',
        'admin_email_ignored' => '      已忽略 --admin-email :email：安裝程式只建立第一位管理員，其他請用 threads:create-admin。',
        'admin_env_without_email' => '已設定 :env，但沒有 --admin-email，所以沒有建立管理員。請加上 --admin-email=<email> 再執行一次。',
        'admin_none' => '[5/7] 沒有建立管理員帳號。:later',
        'admin_confirm' => '現在建立管理員帳號嗎？',
        'admin_no_email' => '試了 :n 次都沒有有效的 Email。',
        'admin_no_password' => '試了 :n 次都沒有有效的密碼。',
        'admin_no_password_env' => '沒有密碼：使用 --no-interaction 時請設定環境變數 :env。',
        'admin_created' => '[5/7] 已建立管理員 :email。',
        'admin_email_prompt' => '管理員 Email',
        'try_again' => '請再試一次。',
        'step5_error' => '[5/7] :error',

        'writable_ok' => '[6/7] :user 可以寫入 storage、bootstrap/cache 與 database。',
        'writable_hint' => '      在伺服器上，網頁伺服器的使用者也要能寫入這些目錄：見 docs/SELF-HOSTING.zh-TW.md 的「2.1 檔案權限」。',
        'not_writable' => '[6/7] :user 無法寫入：',
        'fix_with' => '      修正方式：',
        'www_data_note' => '      （www-data 是 Debian/Ubuntu 的網頁伺服器群組；如果不同請換成你的。）',
        'run_again' => '      然後再執行一次 php artisan threads:install。',

        'done' => '[7/7] 所有步驟都完成了。接下來：',
        'failed_steps' => '[7/7] 有 :count 個步驟失敗（步驟 :steps）。請先處理上面列出的問題，再執行一次 php artisan threads:install。',
        'remaining' => '  修正後，剩下的設定是：',
        'next_app_url' => '  1. 把 .env 的 APP_URL 設成你的網址，然後執行：php artisan config:clear',
        'next_cron' => '  2. 把排程加進網頁伺服器使用者的 crontab（sudo crontab -u www-data -e）：',
        'next_nginx' => '  3. nginx 設定範例：:path',
        'next_serve' => '  只想在本機試用：php artisan serve',
        'no_admin_warning' => '  注意：目前還沒有任何管理員帳號，沒有人能登入。請建立一個：php artisan threads:create-admin <email>',
    ],
];
