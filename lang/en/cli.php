<?php

// Output of threads:install and threads:create-admin (language: --lang, then LC_ALL / LC_MESSAGES /
// LANG, otherwise English). Keep the keys identical to lang/zh_TW/cli.php.
return [
    'bad_lang' => 'Unknown --lang value ":value". Use zh or en.',

    'admin' => [
        'invalid_email' => 'Invalid email address.',
        'short_password' => 'Password must be at least :min characters.',
        'password_prompt' => 'Password (min :min characters)',
        'confirm_prompt' => 'Confirm password',
        'mismatch' => 'Passwords do not match.',
        'updated' => 'Password updated for :email.',
        'created' => 'Admin :email created.',
        'needs_tty' => 'An interactive terminal is needed to type the password, or set :env.',
    ],

    'install' => [
        'title' => 'Threads Monitor installer',

        'ext_ok' => '[0/7] PHP extensions OK (:list).',
        'ext_missing' => '[0/7] Missing PHP extensions: :list',
        'ext_apt' => '      Debian / Ubuntu: sudo apt install -y :packages',
        'ext_fpm' => '      (PHP-FPM needs them too: sudo systemctl restart php:php-fpm.) Then run the installer again.',

        'env_exists' => '[1/7] .env already exists; left unchanged.',
        'env_no_example' => '[1/7] Neither .env nor .env.example exists in :path.',
        'env_create_failed' => '[1/7] Could not create :path. Is the directory writable?',
        'env_created_640' => '[1/7] Created .env from .env.example (permissions 640).',
        'env_created' => '[1/7] Created .env from .env.example.',
        'env_chmod_failed' => '      Could not set its permissions to 640; run: chmod 640 :path',

        'key_read_failed' => '[2/7] Could not read :path.',
        'key_exists' => '[2/7] APP_KEY already set; left unchanged.',
        'key_write_failed' => '[2/7] Could not write APP_KEY to :path. Is the file writable?',
        'key_generated' => '[2/7] Generated APP_KEY (stored in .env, not shown).',
        'env_parse_failed' => '[2/7] .env could not be parsed; fix its syntax and run the installer again.',
        'secure_cookie_failed' => '[2/7] APP_URL uses https://, but SESSION_SECURE_COOKIE=true could not be added to :path; add it by hand.',
        'secure_cookie_set' => '[2/7] APP_URL uses https://: set SESSION_SECURE_COOKIE=true in .env.',

        'db_not_sqlite' => '[3/7] Database connection is :connection; no SQLite file needed.',
        'db_memory' => '[3/7] SQLite runs in memory; no file needed.',
        'db_exists' => '[3/7] SQLite database :path already exists; left unchanged.',
        'db_create_failed' => '[3/7] Could not create the SQLite database :path.',
        'db_created' => '[3/7] Created the SQLite database :path.',

        'migrate_start' => '[4/7] Creating / updating tables (migrate --force)...',
        'migrate_failed' => '[4/7] migrate failed: :message',
        'migrate_check_db' => '      Check the database settings in .env (DB_*).',
        'migrate_readonly' => '      The SQLite database :path (or its directory) is not writable by this user; the settings in .env are fine.',
        'migrate_failed_above' => '[4/7] migrate failed (see the message above). Check the database settings in .env (DB_*).',
        'sqlite_reset' => '      The SQLite database holds no accounts yet, so you can safely start it over:',

        'admin_later' => 'Create one later with: php artisan threads:create-admin <email>',
        'admin_exists' => '[5/7] An admin account already exists; skipped. To add another admin or reset a password: php artisan threads:create-admin <email>',
        'admin_email_ignored' => '      --admin-email :email was ignored: the installer only creates the first admin. Use threads:create-admin for more.',
        'admin_env_without_email' => ':env is set but --admin-email is missing, so no admin was created. Run again with --admin-email=<email>.',
        'admin_none' => '[5/7] No admin account created. :later',
        'admin_confirm' => 'Create an admin account now?',
        'admin_no_email' => 'No valid email after :n attempts.',
        'admin_no_password' => 'No valid password after :n attempts.',
        'admin_no_password_env' => 'No password given: set the :env environment variable when using --no-interaction.',
        'admin_created' => '[5/7] Admin :email created.',
        'admin_email_prompt' => 'Admin email',
        'try_again' => ' Try again.',
        'step5_error' => '[5/7] :error',

        'writable_ok' => '[6/7] storage, bootstrap/cache and database are writable by :user.',
        'writable_hint' => '      On a server the web user must be able to write there too: see docs/SELF-HOSTING.md, 2.1 File permissions.',
        'not_writable' => '[6/7] Not writable by :user:',
        'fix_with' => '      Fix it with:',
        'www_data_note' => '      (www-data is the web server group on Debian/Ubuntu; use yours if it differs.)',
        'run_again' => '      Then run php artisan threads:install again.',

        'done' => '[7/7] All steps completed. Next steps:',
        'failed_steps' => '{1} [7/7] 1 step failed (step :steps). Fix the problems shown above, then run php artisan threads:install again.|[2,*] [7/7] :count steps failed (step :steps). Fix the problems shown above, then run php artisan threads:install again.',
        'remaining' => '  Once fixed, the remaining setup is:',
        'next_app_url' => '  1. Set APP_URL in .env to your address, then run: php artisan config:clear',
        'next_cron' => '  2. Add the scheduler to the web user\'s crontab (sudo crontab -u www-data -e):',
        'next_nginx' => '  3. nginx example configuration: :path',
        'next_serve' => '  Quick local try instead: php artisan serve',
        'no_admin_warning' => '  Warning: there is no admin account yet, so nobody can sign in. Create one: php artisan threads:create-admin <email>',
    ],
];
