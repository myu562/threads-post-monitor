<?php

namespace Tests\Feature;

use App\Console\Commands\Install;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * threads:install against a throwaway project root (environment, storage, bootstrap and database
 * paths all point into a temp directory). The database itself is phpunit's sqlite :memory:.
 */
class InstallCommandTest extends TestCase
{
    private const PASSWORD = 'fake-installer-password-123';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/threads-install-test-'.bin2hex(random_bytes(6));
        foreach (['storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache', 'database'] as $dir) {
            mkdir($this->root.'/'.$dir, 0775, true);
        }
        file_put_contents($this->root.'/.env.example', "APP_NAME=\"Threads Monitor\"\nAPP_ENV=production\nAPP_KEY=\nDB_CONNECTION=sqlite\n");

        $this->app->useEnvironmentPath($this->root);
        $this->app->useStoragePath($this->root.'/storage');
        $this->app->useBootstrapPath($this->root.'/bootstrap');
        $this->app->useDatabasePath($this->root.'/database');
        // database_path() now points at the temp root; keep running the real migrations.
        $this->app['migrator']->path(base_path('database/migrations'));

        $this->setPasswordEnv(null);
    }

    protected function tearDown(): void
    {
        $this->setPasswordEnv(null);
        Install::$extensionLoaded = null;
        Install::$groupExists = null;
        putenv('DB_PASSWORD');
        unset($_ENV['DB_PASSWORD'], $_SERVER['DB_PASSWORD']);
        foreach (['storage', 'storage/logs', 'database', 'bootstrap/cache'] as $dir) {
            @chmod($this->root.'/'.$dir, 0775);
        }
        // Literal temp root created in setUp (never empty: sys_get_temp_dir + random suffix).
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function setPasswordEnv(?string $value): void
    {
        if ($value === null) {
            putenv('THREADS_ADMIN_PASSWORD');
            unset($_ENV['THREADS_ADMIN_PASSWORD'], $_SERVER['THREADS_ADMIN_PASSWORD']);

            return;
        }
        putenv('THREADS_ADMIN_PASSWORD='.$value);
        $_ENV['THREADS_ADMIN_PASSWORD'] = $value;
        $_SERVER['THREADS_ADMIN_PASSWORD'] = $value;
    }

    private function envFile(): string
    {
        return $this->root.'/.env';
    }

    private function appKeyInFile(): string
    {
        preg_match('/^APP_KEY=(.*)$/m', (string) file_get_contents($this->envFile()), $m);

        return $m[1] ?? '';
    }

    public function test_first_install_creates_env_key_tables_and_prints_next_steps(): void
    {
        $this->assertSame(0, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('Created .env from .env.example (permissions 640).', $out);
        $this->assertStringContainsString('Generated APP_KEY (stored in .env, not shown).', $out);
        $this->assertStringContainsString('No admin account created. Create one later with: php artisan threads:create-admin <email>', $out);
        $this->assertStringContainsString('[7/7] All steps completed.', $out);
        $this->assertStringContainsString(base_path('docs/deploy/nginx.conf.example'), $out);
        $this->assertStringNotContainsString('base64:', $out);

        $this->assertFileExists($this->envFile());
        $this->assertSame('640', substr(sprintf('%o', fileperms($this->envFile())), -3));
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $this->appKeyInFile());
        $this->assertSame(1, substr_count((string) file_get_contents($this->envFile()), 'APP_KEY='));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('tracked_posts'));
        $this->assertSame(0, User::count());
    }

    public function test_second_run_keeps_env_and_app_key_and_does_not_add_a_second_admin(): void
    {
        $this->setPasswordEnv(self::PASSWORD);
        $this->artisan('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true])->assertSuccessful();

        file_put_contents($this->envFile(), "# my own edit\n", FILE_APPEND);
        $before = file_get_contents($this->envFile());
        $key = $this->appKeyInFile();
        $hash = User::sole()->password;

        $this->setPasswordEnv('another-fake-password-456');
        $this->artisan('threads:install', ['--admin-email' => 'second@example.test', '--no-interaction' => true])
            ->expectsOutputToContain('.env already exists; left unchanged')
            ->expectsOutputToContain('APP_KEY already set; left unchanged')
            ->expectsOutputToContain('An admin account already exists; skipped')
            ->expectsOutputToContain('--admin-email second@example.test was ignored')
            ->assertSuccessful();

        $this->assertSame($before, file_get_contents($this->envFile()));
        $this->assertSame($key, $this->appKeyInFile());
        $this->assertSame(1, User::count());
        $this->assertSame($hash, User::sole()->password);
    }

    public function test_existing_env_with_empty_key_gets_a_key_and_keeps_other_lines(): void
    {
        file_put_contents($this->envFile(), "APP_NAME=Mine\nAPP_KEY=\nCUSTOM_SETTING=keep-me\n");

        $this->artisan('threads:install', ['--no-interaction' => true])
            ->expectsOutputToContain('.env already exists; left unchanged')
            ->expectsOutputToContain('Generated APP_KEY')
            ->assertSuccessful();

        $contents = (string) file_get_contents($this->envFile());
        $this->assertStringContainsString("APP_NAME=Mine\n", $contents);
        $this->assertStringContainsString("CUSTOM_SETTING=keep-me\n", $contents);
        $this->assertStringStartsWith('base64:', $this->appKeyInFile());
    }

    public function test_non_interactive_admin_from_env_password(): void
    {
        $this->setPasswordEnv(self::PASSWORD);

        $this->artisan('threads:install', ['--admin-email' => ' Admin@Example.test ', '--no-interaction' => true])
            ->expectsOutputToContain('Admin admin@example.test created.')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();

        $admin = User::sole();
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertTrue($admin->is_admin);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
    }

    public function test_non_interactive_admin_without_password_fails_and_creates_nobody(): void
    {
        $this->artisan('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true])
            ->expectsOutputToContain('set the THREADS_ADMIN_PASSWORD environment variable')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_short_env_password_is_rejected_with_create_admin_rules(): void
    {
        $this->setPasswordEnv('short');

        $this->artisan('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true])
            ->expectsOutputToContain('Password must be at least 12 characters.')
            ->doesntExpectOutputToContain('short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_invalid_admin_email_is_rejected(): void
    {
        $this->setPasswordEnv(self::PASSWORD);

        $this->artisan('threads:install', ['--admin-email' => 'not-an-email', '--no-interaction' => true])
            ->expectsOutputToContain('Invalid email address.')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_interactive_admin_prompts(): void
    {
        $this->artisan('threads:install')
            ->expectsConfirmation('Create an admin account now?', 'yes')
            ->expectsQuestion('Admin email', 'boss@example.test')
            ->expectsQuestion('Password (min 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutputToContain('Admin boss@example.test created.')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();

        $this->assertTrue(User::sole()->is_admin);
    }

    public function test_interactive_can_decline_admin(): void
    {
        $this->artisan('threads:install')
            ->expectsConfirmation('Create an admin account now?', 'no')
            ->expectsOutputToContain('No admin account created')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_without_email_non_interactive_skips_admin_and_explains(): void
    {
        $this->artisan('threads:install', ['--no-interaction' => true])
            ->expectsOutputToContain('No admin account created. Create one later with: php artisan threads:create-admin <email>')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_unwritable_directory_prints_fix_commands_with_paths(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anywhere');
        }
        chmod($this->root.'/storage/logs', 0555);

        Install::$groupExists = fn (string $group) => $group === 'www-data';

        $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();

        $dir = escapeshellarg($this->root.'/storage');
        $this->assertStringContainsString('Not writable by', $out);
        $this->assertStringContainsString('- '.$this->root.'/storage', $out);
        $this->assertStringContainsString('sudo chgrp -R www-data '.$dir, $out);
        $this->assertStringContainsString('sudo chmod -R ug+rwX '.$dir, $out);
        $this->assertStringContainsString('sudo find '.$dir.' -type d -exec chmod g+s {} +', $out);
        $this->assertStringNotContainsString('sudo chown', $out); // already owned by the current user
        $this->assertStringContainsString('[7/7] 1 step failed (step 6).', $out);
        $this->assertStringNotContainsString('All steps completed', $out);
    }

    public function test_writable_directories_are_reported_ok(): void
    {
        $this->artisan('threads:install', ['--no-interaction' => true])
            ->expectsOutputToContain('storage, bootstrap/cache and database are writable')
            ->doesntExpectOutputToContain('Not writable by')
            ->assertSuccessful();
    }

    public function test_creates_sqlite_file_at_configured_path(): void
    {
        file_put_contents($this->envFile(), 'APP_KEY=base64:'.base64_encode(str_repeat('k', 32))."\n");
        $file = $this->root.'/database/database.sqlite';
        config(['database.connections.sqlite.database' => $file]);
        DB::purge('sqlite');

        $this->artisan('threads:install', ['--no-interaction' => true])
            ->expectsOutputToContain("Created the SQLite database {$file}.")
            ->assertSuccessful();
        $this->assertFileExists($file);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('users'));

        $this->artisan('threads:install', ['--no-interaction' => true])
            ->expectsOutputToContain("SQLite database {$file} already exists; left unchanged.")
            ->assertSuccessful();

        DB::purge('sqlite');
    }

    public function test_output_never_contains_key_or_password(): void
    {
        $this->setPasswordEnv(self::PASSWORD);

        Artisan::call('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true]);
        $first = Artisan::output();
        Artisan::call('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true]);
        $second = Artisan::output();

        $key = $this->appKeyInFile();
        $this->assertNotSame('', $key);
        foreach ([$first, $second] as $out) {
            $this->assertStringNotContainsString($key, $out);
            $this->assertStringNotContainsString(substr($key, 7, 12), $out);
            $this->assertStringNotContainsString(substr($key, -12), $out);
            $this->assertStringNotContainsString(self::PASSWORD, $out);
            $this->assertStringNotContainsString('base64:', $out);
        }
    }

    // ---- Y1: migrate errors ----

    /** Adds a migration that creates a table and then throws the contents of fail.flag (if present). */
    private function addFailingMigration(string $message): string
    {
        $dir = $this->root.'/extra-migrations';
        mkdir($dir);
        file_put_contents($dir.'/2099_01_01_000000_fx_fail.php', <<<'PHP'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;
        return new class extends Migration {
            public function up(): void
            {
                Schema::create('half_done', fn (Blueprint $t) => $t->id());
                if (is_file(__DIR__.'/fail.flag')) {
                    throw new RuntimeException(file_get_contents(__DIR__.'/fail.flag'));
                }
            }
        };
        PHP);
        file_put_contents($dir.'/fail.flag', $message);
        $this->app['migrator']->path($dir);

        return $dir;
    }

    public function test_migrate_failure_shows_first_line_with_password_masked_and_cut_to_300(): void
    {
        // Existing .env with a key: no configuration reload, so the config value below stays in effect.
        file_put_contents($this->envFile(), 'APP_KEY=base64:'.base64_encode(str_repeat('k', 32))."\n");
        config(['database.connections.sqlite.password' => 'fake-db-pass-9876']);
        putenv('DB_PASSWORD=fake-env-pass-5432');
        $_ENV['DB_PASSWORD'] = 'fake-env-pass-5432';
        $this->addFailingMigration('SQLSTATE[HY000] boom fake-db-pass-9876 fake-env-pass-5432 '.str_repeat('x', 400)."\nsecond line fake-tail");

        $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('[4/7] migrate failed: SQLSTATE[HY000] boom *** *** '.str_repeat('x', 271).'...', $out);
        $this->assertStringNotContainsString(str_repeat('x', 272), $out);
        $this->assertStringNotContainsString('fake-db-pass-9876', $out);
        $this->assertStringNotContainsString('fake-env-pass-5432', $out);
        $this->assertStringNotContainsString('second line', $out);
        $this->assertStringNotContainsString('RuntimeException', $out);
        $this->assertStringContainsString('[7/7] 1 step failed (step 4).', $out);
        $this->assertStringNotContainsString('All steps completed', $out);
        $this->assertStringNotContainsString('Next steps', $out);
    }

    public function test_interrupted_sqlite_migrate_leaves_nothing_behind_and_rerun_succeeds(): void
    {
        $dir = $this->addFailingMigration('simulated crash in the middle of migrate');

        $this->artisan('threads:install', ['--no-interaction' => true])->assertFailed();

        // The whole run was one transaction: no table of any migration survives, nothing is recorded.
        $schema = DB::getSchemaBuilder();
        $this->assertFalse($schema->hasTable('users'));
        $this->assertFalse($schema->hasTable('half_done'));
        $this->assertTrue(! $schema->hasTable('migrations') || DB::table('migrations')->count() === 0);
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));

        unlink($dir.'/fail.flag');
        $this->assertSame(0, Artisan::call('threads:install', ['--no-interaction' => true]), Artisan::output());
        $this->assertTrue($schema->hasTable('users'));
        $this->assertTrue($schema->hasTable('half_done'));
        $this->assertSame(9, DB::table('migrations')->count());
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
    }

    private function useSqliteFile(): string
    {
        file_put_contents($this->envFile(), 'APP_KEY=base64:'.base64_encode(str_repeat('k', 32))."\n");
        $file = $this->root.'/database/database.sqlite';
        touch($file);
        config(['database.connections.sqlite.database' => $file]);
        DB::purge('sqlite');

        return $file;
    }

    public function test_stuck_sqlite_without_accounts_prints_literal_recovery_commands_and_deletes_nothing(): void
    {
        $file = $this->useSqliteFile();
        // Leftover of an old interrupted run: users table created, migration never recorded.
        DB::statement('create table users (id integer primary key)');

        $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();
        DB::purge('sqlite');

        $this->assertStringContainsString('[4/7] migrate failed: SQLSTATE', $out);
        $this->assertStringContainsString('already exists', $out);
        $this->assertStringContainsString('holds no accounts yet', $out);
        $this->assertStringContainsString('rm '.escapeshellarg(realpath($file)), $out);
        $this->assertStringContainsString(PHP_BINARY.' artisan threads:install', $out);
        $this->assertFileExists($file);
    }

    public function test_no_recovery_commands_when_the_sqlite_database_has_accounts(): void
    {
        $this->useSqliteFile();
        DB::statement('create table users (id integer primary key)');
        DB::statement('insert into users (id) values (1)');

        $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();
        DB::purge('sqlite');

        $this->assertStringContainsString('[4/7] migrate failed', $out);
        $this->assertStringNotContainsString('holds no accounts', $out);
        $this->assertStringNotContainsString('rm ', $out);
    }

    // ---- Y2: PHP extensions ----

    public function test_missing_extensions_stop_before_anything_is_written(): void
    {
        Install::$extensionLoaded = fn (string $ext) => ! in_array($ext, ['pdo_sqlite', 'bcmath'], true);
        $php = 'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('[0/7] Missing PHP extensions: pdo_sqlite, bcmath', $out);
        $this->assertStringContainsString("sudo apt install -y {$php}-sqlite3 {$php}-bcmath", $out);
        $this->assertStringContainsString('[7/7] 1 step failed (step 0).', $out);
        $this->assertFileDoesNotExist($this->envFile());
    }

    public function test_mysql_connection_requires_pdo_mysql(): void
    {
        config(['database.default' => 'mysql']);
        Install::$extensionLoaded = fn (string $ext) => $ext !== 'pdo_mysql';
        $php = 'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();
        config(['database.default' => 'sqlite']);

        $this->assertStringContainsString('Missing PHP extensions: pdo_mysql', $out);
        $this->assertStringContainsString("sudo apt install -y {$php}-mysql", $out);
        $this->assertStringNotContainsString('pdo_sqlite', $out);
    }

    public function test_all_extensions_present_is_reported(): void
    {
        $this->artisan('threads:install', ['--no-interaction' => true])
            ->expectsOutputToContain('[0/7] PHP extensions OK (pdo_sqlite, bcmath, mbstring, curl, xml).')
            ->assertSuccessful();
    }

    public function test_composer_json_declares_php_and_extensions(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $this->assertSame('^8.3', $composer['require']['php']);
        foreach (['bcmath', 'ctype', 'curl', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml'] as $ext) {
            $this->assertArrayHasKey('ext-'.$ext, $composer['require']);
        }
        $this->assertArrayHasKey('ext-pdo_sqlite', $composer['suggest']);
        $this->assertArrayHasKey('ext-pdo_mysql', $composer['suggest']);
    }

    // ---- Y3: exit codes, retries, summary ----

    public function test_interactive_email_is_asked_again_after_a_typo(): void
    {
        $this->artisan('threads:install')
            ->expectsConfirmation('Create an admin account now?', 'yes')
            ->expectsQuestion('Admin email', 'boss-at-example')
            ->expectsQuestion('Admin email', 'boss@example.test')
            ->expectsQuestion('Password (min 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutputToContain('Invalid email address. Try again.')
            ->expectsOutputToContain('Admin boss@example.test created.')
            ->assertSuccessful();

        $this->assertSame('boss@example.test', User::sole()->email);
    }

    public function test_interactive_email_gives_up_after_three_attempts(): void
    {
        $this->artisan('threads:install')
            ->expectsConfirmation('Create an admin account now?', 'yes')
            ->expectsQuestion('Admin email', 'a')
            ->expectsQuestion('Admin email', 'b')
            ->expectsQuestion('Admin email', 'c')
            ->expectsOutputToContain('No valid email after 3 attempts.')
            ->expectsOutputToContain('[7/7] 1 step failed (step 5).')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_interactive_password_is_asked_again_and_gives_up_after_three_attempts(): void
    {
        $this->artisan('threads:install')
            ->expectsConfirmation('Create an admin account now?', 'yes')
            ->expectsQuestion('Admin email', 'boss@example.test')
            ->expectsQuestion('Password (min 12 characters)', 'short')
            ->expectsQuestion('Password (min 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', 'something-else-entirely')
            ->expectsQuestion('Password (min 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', 'still-not-the-same-one')
            ->expectsOutputToContain('Passwords do not match. Try again.')
            ->expectsOutputToContain('No valid password after 3 attempts.')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_interactive_password_mismatch_then_match_succeeds(): void
    {
        $this->artisan('threads:install')
            ->expectsConfirmation('Create an admin account now?', 'yes')
            ->expectsQuestion('Admin email', 'boss@example.test')
            ->expectsQuestion('Password (min 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', 'typo-typo-typo-typo')
            ->expectsQuestion('Password (min 12 characters)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutputToContain('Admin boss@example.test created.')
            ->assertSuccessful();
    }

    public function test_env_password_without_admin_email_fails_non_interactive(): void
    {
        $this->setPasswordEnv(self::PASSWORD);

        $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('THREADS_ADMIN_PASSWORD is set but --admin-email is missing', $out);
        $this->assertStringContainsString('[7/7] 1 step failed (step 5).', $out);
        $this->assertStringNotContainsString(self::PASSWORD, $out);
        $this->assertSame(0, User::count());
    }

    public function test_rerun_with_the_same_admin_email_is_quiet_and_successful(): void
    {
        $this->setPasswordEnv(self::PASSWORD);
        $this->artisan('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true])->assertSuccessful();

        $this->artisan('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true])
            ->expectsOutputToContain('An admin account already exists; skipped')
            ->doesntExpectOutputToContain('was ignored')
            ->assertSuccessful();
    }

    public function test_several_failures_are_counted_in_the_summary(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anywhere');
        }
        chmod($this->root.'/storage/logs', 0555);
        $this->setPasswordEnv('short');

        $this->assertSame(1, Artisan::call('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true]));
        $this->assertStringContainsString('[7/7] 2 steps failed (step 5, 6).', Artisan::output());
    }

    // ---- Y4: cron line ----

    public function test_cron_line_uses_the_absolute_php_binary(): void
    {
        $this->assertSame(0, Artisan::call('threads:install', ['--no-interaction' => true]));

        $this->assertStringContainsString('* * * * * cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1', Artisan::output());
        $this->assertStringStartsWith('/', PHP_BINARY);
        $this->assertStringContainsString('full path', (string) file_get_contents(base_path('docs/deploy/cron.example')));
    }

    // ---- Y5: permission advice ----

    public function test_without_www_data_group_only_user_write_is_suggested(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anywhere');
        }
        chmod($this->root.'/storage/logs', 0555);
        Install::$groupExists = fn () => false;

        $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('        chmod -R u+w '.escapeshellarg($this->root.'/storage'), $out);
        $this->assertStringNotContainsString('chgrp', $out);
        $this->assertStringNotContainsString('sudo chmod', $out);
        $this->assertStringNotContainsString('sudo chown', $out);
    }

    public function test_log_files_are_created_group_writable(): void
    {
        $this->assertSame(0664, config('logging.channels.single.permission'));
        $this->assertSame(0664, config('logging.channels.daily.permission'));
    }

    // ---- Y6: secure cookie / HSTS ----

    public function test_https_app_url_turns_on_secure_cookies(): void
    {
        file_put_contents($this->envFile(), "APP_KEY=\nAPP_URL=https://threads.example.test\n");

        $this->artisan('threads:install', ['--no-interaction' => true])
            ->expectsOutputToContain('APP_URL uses https://: set SESSION_SECURE_COOKIE=true in .env.')
            ->assertSuccessful();

        $contents = (string) file_get_contents($this->envFile());
        $this->assertSame(1, substr_count($contents, 'SESSION_SECURE_COOKIE=true'));
        $this->assertTrue((bool) config('session.secure'));

        // Second run: key already there, file unchanged.
        $this->artisan('threads:install', ['--no-interaction' => true])
            ->doesntExpectOutputToContain('SESSION_SECURE_COOKIE')
            ->assertSuccessful();
        $this->assertSame($contents, file_get_contents($this->envFile()));
    }

    public function test_existing_secure_cookie_setting_is_never_changed(): void
    {
        file_put_contents($this->envFile(), "APP_KEY=\nAPP_URL=https://threads.example.test\nSESSION_SECURE_COOKIE=false\n");

        $this->artisan('threads:install', ['--no-interaction' => true])->assertSuccessful();

        $contents = (string) file_get_contents($this->envFile());
        $this->assertStringContainsString("SESSION_SECURE_COOKIE=false\n", $contents);
        $this->assertStringNotContainsString('SESSION_SECURE_COOKIE=true', $contents);
    }

    public function test_http_app_url_leaves_secure_cookie_alone(): void
    {
        file_put_contents($this->envFile(), "APP_KEY=\nAPP_URL=http://localhost\n");

        $this->artisan('threads:install', ['--no-interaction' => true])->assertSuccessful();

        $this->assertStringNotContainsString('SESSION_SECURE_COOKIE', (string) file_get_contents($this->envFile()));
    }

    public function test_deploy_examples_document_secure_cookie_and_hsts(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^# .*SESSION_SECURE_COOKIE/m', $example);
        $this->assertStringContainsString('Strict-Transport-Security "max-age=31536000"', (string) file_get_contents(base_path('docs/deploy/nginx.conf.example')));
    }

    // ---- Y7: .env.example ----

    public function test_env_example_is_documented(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString("APP_NAME=\"Threads Monitor\"\n", $example);
        $this->assertStringContainsString('Appendix A', $example);
        $this->assertStringContainsString('docs/SELF-HOSTING.md, section 3', $example);
        $this->assertStringNotContainsString('選用', $example);
    }

    // ---- Output language (contract L5) ----

    public function test_lang_zh_prints_chinese_and_does_the_same_work(): void
    {
        $this->assertSame(0, Artisan::call('threads:install', ['--no-interaction' => true, '--lang' => 'zh']));
        $out = Artisan::output();

        $this->assertStringContainsString('Threads Monitor 安裝程式', $out);
        $this->assertStringContainsString('[1/7] 已從 .env.example 建立 .env（權限 640）。', $out);
        $this->assertStringContainsString('[2/7] 已產生 APP_KEY（存在 .env，不顯示）。', $out);
        $this->assertStringContainsString('[5/7] 沒有建立管理員帳號。之後可以用這個指令建立：php artisan threads:create-admin <email>', $out);
        $this->assertStringContainsString('[7/7] 所有步驟都完成了。接下來：', $out);
        $this->assertStringContainsString(base_path('docs/deploy/nginx.conf.example'), $out);
        $this->assertStringNotContainsString('Created .env', $out);
        $this->assertStringNotContainsString('base64:', $out);
        $this->assertMatchesRegularExpression('/^base64:/', $this->appKeyInFile());
    }

    public function test_language_follows_lang_environment_variables(): void
    {
        putenv('LANG=zh_TW.UTF-8');
        $this->assertSame(0, Artisan::call('threads:install', ['--no-interaction' => true]));
        $this->assertStringContainsString('[7/7] 所有步驟都完成了。', Artisan::output());

        // LC_ALL wins over LANG: C means English.
        putenv('LC_ALL=C');
        Artisan::call('threads:install', ['--no-interaction' => true]);
        $this->assertStringContainsString('.env already exists; left unchanged.', Artisan::output());
        putenv('LC_ALL');

        // --lang wins over the environment.
        Artisan::call('threads:install', ['--no-interaction' => true, '--lang' => 'en']);
        $this->assertStringContainsString('[7/7] All steps completed.', Artisan::output());
    }

    public function test_chinese_failure_summary_and_admin_errors(): void
    {
        $this->setPasswordEnv('short');
        $this->assertSame(1, Artisan::call('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true, '--lang' => 'zh']));
        $out = Artisan::output();

        $this->assertStringContainsString('[5/7] THREADS_ADMIN_PASSWORD: 密碼至少要 12 個字元。', $out);
        $this->assertStringContainsString('[7/7] 有 1 個步驟失敗（步驟 5）。', $out);
        $this->assertSame(0, User::count());
    }

    public function test_unknown_lang_value_fails_before_doing_anything(): void
    {
        $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true, '--lang' => 'fr']));
        $this->assertStringContainsString('Unknown --lang value "fr". Use zh or en.', Artisan::output());
        $this->assertFileDoesNotExist($this->envFile());
    }

    // ---- RA-F9 / F11 / F12 ----

    public function test_readonly_sqlite_file_prints_permission_fix_not_db_settings(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anywhere');
        }
        file_put_contents($this->envFile(), 'APP_KEY=base64:'.base64_encode(str_repeat('k', 32))."\n");
        $file = $this->root.'/database/readonly.sqlite';
        touch($file);
        chmod($file, 0444);
        config(['database.connections.sqlite.database' => $file]);
        DB::purge('sqlite');
        Install::$groupExists = fn (string $group) => $group === 'www-data';

        try {
            $this->assertSame(1, Artisan::call('threads:install', ['--no-interaction' => true]));
            $out = Artisan::output();
        } finally {
            DB::purge('sqlite');
            chmod($file, 0664);
        }

        $dir = escapeshellarg($this->root.'/database');
        $this->assertStringContainsString('[4/7] migrate failed:', $out);
        $this->assertStringContainsString("The SQLite database {$file} (or its directory) is not writable by this user", $out);
        $this->assertStringContainsString('sudo chgrp -R www-data '.$dir, $out);
        $this->assertStringContainsString('sudo chmod -R ug+rwX '.$dir, $out);
        $this->assertStringNotContainsString('Check the database settings', $out);
        // Step 6 also checks the database file itself, not only the directories.
        $this->assertStringContainsString('[6/7] Not writable by', $out);
        $this->assertStringContainsString('- '.$file, $out);
        $this->assertStringContainsString('step 4', $out);
        $this->assertStringContainsString('6).', $out);
    }

    public function test_non_interactive_install_without_admin_warns_in_the_summary_and_exits_0(): void
    {
        $this->assertSame(0, Artisan::call('threads:install', ['--no-interaction' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('[7/7] All steps completed.', $out);
        $this->assertStringContainsString('Warning: there is no admin account yet, so nobody can sign in. Create one: php artisan threads:create-admin <email>', $out);
        $this->assertGreaterThan(strpos($out, '[7/7]'), strpos($out, 'Warning: there is no admin account yet'));

        $this->assertSame(0, Artisan::call('threads:install', ['--no-interaction' => true, '--lang' => 'zh']));
        $this->assertStringContainsString('注意：目前還沒有任何管理員帳號，沒有人能登入。', Artisan::output());
    }

    public function test_no_admin_warning_once_an_admin_exists(): void
    {
        $this->setPasswordEnv(self::PASSWORD);
        $this->assertSame(0, Artisan::call('threads:install', ['--admin-email' => 'admin@example.test', '--no-interaction' => true]));
        $this->assertStringNotContainsString('no admin account yet', Artisan::output());
    }

    public function test_chinese_permission_hint_points_to_the_chinese_guide(): void
    {
        $this->assertSame(0, Artisan::call('threads:install', ['--no-interaction' => true, '--lang' => 'zh']));
        $out = Artisan::output();

        $this->assertStringContainsString('docs/SELF-HOSTING.zh-TW.md 的「2.1 檔案權限」', $out);
        $this->assertStringNotContainsString('File permissions', $out);
        $this->assertFileExists(base_path('docs/SELF-HOSTING.zh-TW.md'));
        $this->assertStringContainsString('### 2.1 檔案權限', (string) file_get_contents(base_path('docs/SELF-HOSTING.zh-TW.md')));
    }
}
