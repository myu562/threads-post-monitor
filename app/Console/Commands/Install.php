<?php

namespace App\Console\Commands;

use App\Console\CliLocale;
use App\Models\User;
use App\Services\AdminAccounts;
use Closure;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * One-command setup for a fresh clone (docs/SELF-HOSTING.md, "Step 3: run the installer").
 *
 * Safe to run again: it never overwrites .env, never replaces an existing APP_KEY and never
 * creates a second admin. It never prints APP_KEY, database credentials or passwords.
 * Exit code: 0 only when every step succeeded.
 */
class Install extends Command
{
    protected $signature = 'threads:install
        {--admin-email= : Create this admin without prompts (password from the THREADS_ADMIN_PASSWORD environment variable)}
        {--lang= : '.CliLocale::OPTION_HELP.'}';

    protected $description = 'Set up a fresh installation: PHP extensions, .env, APP_KEY, SQLite file, tables, first admin, permission check, cron line';

    private const PASSWORD_ENV = AdminAccounts::PASSWORD_ENV;

    private const MAX_ATTEMPTS = 3;

    /** apt package suffix (php8.x-<suffix>) for each PHP extension whose package name differs. */
    private const APT_PACKAGE = ['pdo_sqlite' => 'sqlite3', 'pdo_mysql' => 'mysql', 'pdo_pgsql' => 'pgsql'];

    /** Test seam: replaces extension_loaded(). @var (Closure(string): bool)|null */
    public static ?Closure $extensionLoaded = null;

    /** Test seam: replaces the "does the www-data group exist" lookup. @var (Closure(string): bool)|null */
    public static ?Closure $groupExists = null;

    /** @var list<int> */
    private array $failedSteps = [];

    /** Output language (contract L5), set at the start of handle(). */
    private string $locale = 'en';

    public function handle(AdminAccounts $admins): int
    {
        $this->failedSteps = []; // the command object is reused when called more than once in one process
        $locale = CliLocale::resolve($this->option('lang'));
        if ($locale === null) {
            $this->error((string) trans('cli.bad_lang', ['value' => (string) $this->option('lang')], 'en'));

            return self::FAILURE;
        }
        $this->locale = $locale;
        $this->info($this->t('title'));
        $this->newLine();

        if (! $this->checkExtensions()) {
            return $this->finish(false);
        }

        $envChanged = false;
        if (! $this->ensureEnvFile($envChanged) || ! $this->ensureAppKey($envChanged)) {
            return $this->finish(false);
        }
        $this->ensureSecureCookie($envChanged);
        if ($envChanged) {
            $this->reloadConfiguration();
        }

        if (! $this->ensureSqliteFile() || ! $this->migrate()) {
            $this->checkWritableDirectories();

            return $this->finish(false);
        }

        $this->createAdmin($admins);
        $this->checkWritableDirectories();

        return $this->finish(true);
    }

    private function markFailed(int $step): void
    {
        if (! in_array($step, $this->failedSteps, true)) {
            $this->failedSteps[] = $step;
        }
    }

    // ---- 0. PHP extensions ----

    private function checkExtensions(): bool
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        $needed = match ($driver) {
            'sqlite' => ['pdo_sqlite'],
            'mysql', 'mariadb' => ['pdo_mysql'],
            'pgsql' => ['pdo_pgsql'],
            default => [],
        };
        $needed = array_merge($needed, ['bcmath', 'mbstring', 'curl', 'xml']);

        $loaded = self::$extensionLoaded ?? fn (string $ext): bool => extension_loaded($ext);
        $missing = array_values(array_filter($needed, fn (string $ext) => ! $loaded($ext)));

        if ($missing === []) {
            $this->line($this->t('ext_ok', ['list' => implode(', ', $needed)]));

            return true;
        }

        $this->markFailed(0);
        $php = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $packages = array_map(fn (string $ext) => "php{$php}-".(self::APT_PACKAGE[$ext] ?? $ext), $missing);
        $this->error($this->t('ext_missing', ['list' => implode(', ', $missing)]));
        $this->line($this->t('ext_apt', ['packages' => implode(' ', $packages)]));
        $this->line($this->t('ext_fpm', ['php' => $php]));

        return false;
    }

    // ---- 1. .env ----

    private function ensureEnvFile(bool &$changed): bool
    {
        $env = $this->envFile();
        if (file_exists($env)) {
            $this->line($this->t('env_exists'));

            return true;
        }

        $example = $this->laravel->environmentPath().DIRECTORY_SEPARATOR.'.env.example';
        if (! is_file($example)) {
            $this->markFailed(1);
            $this->error($this->t('env_no_example', ['path' => $this->laravel->environmentPath()]));

            return false;
        }
        if (! @copy($example, $env)) {
            $this->markFailed(1);
            $this->error($this->t('env_create_failed', ['path' => $env]));
            $this->printFixCommands([$this->laravel->environmentPath()]);

            return false;
        }
        $changed = true;

        if (@chmod($env, 0640)) {
            $this->line($this->t('env_created_640'));
        } else {
            $this->line($this->t('env_created'));
            $this->warn($this->t('env_chmod_failed', ['path' => $env]));
        }

        return true;
    }

    // ---- 2. APP_KEY ----

    private function ensureAppKey(bool &$changed): bool
    {
        $env = $this->envFile();
        $contents = @file_get_contents($env);
        if ($contents === false) {
            $this->markFailed(2);
            $this->error($this->t('key_read_failed', ['path' => $env]));

            return false;
        }

        $parsed = $this->parseEnv($contents);
        if ($parsed === null) {
            $this->markFailed(2);

            return false;
        }
        $current = $parsed['APP_KEY'] ?? '';
        if ($current !== null && $current !== '') {
            $this->line($this->t('key_exists'));

            return true;
        }

        $line = 'APP_KEY=base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')));
        $count = 0;
        $updated = preg_replace_callback('/^[ \t]*(export[ \t]+)?APP_KEY[ \t]*=.*$/m', fn () => $line, $contents, 1, $count);
        if ($count === 0) {
            $updated = rtrim($contents, "\n")."\n".$line."\n";
        }

        if (@file_put_contents($env, $updated, LOCK_EX) === false) {
            $this->markFailed(2);
            $this->error($this->t('key_write_failed', ['path' => $env]));

            return false;
        }
        $changed = true;
        $this->line($this->t('key_generated'));

        return true;
    }

    /** @return array<string, string|null>|null */
    private function parseEnv(string $contents): ?array
    {
        try {
            return Dotenv::parse($contents);
        } catch (Throwable) {
            // Do not echo the parser message: it may quote a line of .env.
            $this->error($this->t('env_parse_failed'));

            return null;
        }
    }

    /** With an https:// APP_URL the session cookie must only travel over HTTPS. Never overrides an existing key. */
    private function ensureSecureCookie(bool &$changed): void
    {
        $env = $this->envFile();
        $contents = @file_get_contents($env);
        $parsed = $contents === false ? null : $this->parseEnv($contents);
        if ($parsed === null) {
            return;
        }

        $url = (string) ($parsed['APP_URL'] ?? Env::get('APP_URL', ''));
        if (! str_starts_with(strtolower(trim($url)), 'https://') || array_key_exists('SESSION_SECURE_COOKIE', $parsed)) {
            return;
        }

        $updated = rtrim((string) $contents, "\n")."\nSESSION_SECURE_COOKIE=true\n";
        if (@file_put_contents($env, $updated, LOCK_EX) === false) {
            $this->warn($this->t('secure_cookie_failed', ['path' => $env]));

            return;
        }
        $changed = true;
        $this->line($this->t('secure_cookie_set'));
    }

    /** Load the new .env into this process so the following steps use it (real environment variables still win). */
    private function reloadConfiguration(): void
    {
        if ($this->laravel->configurationIsCached()) {
            @unlink($this->laravel->getCachedConfigPath());
        }

        Dotenv::create(Env::getRepository(), $this->laravel->environmentPath(), '.env')->safeLoad();
        (new LoadConfiguration)->bootstrap($this->laravel);
        Facade::clearResolvedInstance('config');

        $db = $this->laravel['db'];
        foreach (array_keys($db->getConnections()) as $name) {
            $db->purge($name);
        }
    }

    // ---- 3. SQLite file ----

    /** Path of the default connection's SQLite file, or null when it is not a file-backed SQLite database. */
    private function sqliteFile(): ?string
    {
        $connection = (string) config('database.default');
        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            return null;
        }
        $path = (string) config("database.connections.{$connection}.database");
        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory')) {
            return null;
        }

        return $path;
    }

    private function ensureSqliteFile(): bool
    {
        $connection = (string) config('database.default');
        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            $this->line($this->t('db_not_sqlite', ['connection' => $connection]));

            return true;
        }

        $path = $this->sqliteFile();
        if ($path === null) {
            $this->line($this->t('db_memory'));

            return true;
        }
        if (file_exists($path)) {
            $this->line($this->t('db_exists', ['path' => $path]));

            return true;
        }

        $dir = dirname($path);
        if ((! is_dir($dir) && ! @mkdir($dir, 0775, true)) || ! @touch($path)) {
            $this->markFailed(3);
            $this->error($this->t('db_create_failed', ['path' => $path]));
            $this->printFixCommands([$dir]);

            return false;
        }
        $this->line($this->t('db_created', ['path' => $path]));

        return true;
    }

    // ---- 4. tables ----

    /**
     * Laravel does not wrap SQLite migrations in a transaction, so a run that is interrupted
     * (Ctrl-C, lost SSH session, OOM) used to leave tables behind without their migration record,
     * and every later run failed with "table already exists". SQLite supports transactional DDL,
     * so the whole migrate run is one transaction here: it is applied completely or not at all.
     * Foreign keys are switched off for the run (as Laravel itself does around SQLite table
     * rebuilds; inside a transaction that PRAGMA would otherwise be ignored).
     */
    private function migrate(): bool
    {
        $this->line($this->t('migrate_start'));

        $db = null;
        $atomic = false;
        $foreignKeys = false;
        try {
            /** @var Connection $db */
            $db = $this->laravel['db']->connection();
            $atomic = $db->getDriverName() === 'sqlite';
            if ($atomic) {
                $foreignKeys = (bool) $db->scalar('PRAGMA foreign_keys');
                if ($foreignKeys) {
                    $db->statement('PRAGMA foreign_keys = OFF');
                }
                $db->beginTransaction();
            }

            $code = $this->call('migrate', ['--force' => true]);

            if ($atomic) {
                $code === self::SUCCESS ? $db->commit() : $db->rollBack();
            }
        } catch (Throwable $e) {
            if ($atomic && $db !== null && $db->transactionLevel() > 0) {
                try {
                    $db->rollBack();
                } catch (Throwable) {
                }
            }
            $this->restoreForeignKeys($db, $foreignKeys);
            $this->markFailed(4);
            $this->error($this->t('migrate_failed', ['message' => $this->safeMessage($e)]));
            if (($path = $this->sqliteFile()) !== null && self::isPermissionError($e)) {
                // Not a settings problem: the SQLite file (or its directory) is not writable by this user.
                $this->line($this->t('migrate_readonly', ['path' => $path]));
                $this->printFixCommands([dirname($path)]);

                return false;
            }
            $this->line($this->t('migrate_check_db'));
            $this->printSqliteRecovery();

            return false;
        }
        $this->restoreForeignKeys($db, $foreignKeys);

        if ($code !== self::SUCCESS) {
            $this->markFailed(4);
            $this->error($this->t('migrate_failed_above'));
            $this->printSqliteRecovery();

            return false;
        }

        return true;
    }

    /** SQLite could not write: "attempt to write a readonly database" or "unable to open database file". */
    private static function isPermissionError(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (preg_match('/readonly database|read-only database|unable to open database file/i', $cause->getMessage())) {
                return true;
            }
        }

        return false;
    }

    private function restoreForeignKeys(?Connection $db, bool $wasOn): void
    {
        if ($db !== null && $wasOn) {
            try {
                $db->statement('PRAGMA foreign_keys = ON');
            } catch (Throwable) {
            }
        }
    }

    /** First line of the exception message, database password masked, at most 300 characters. */
    private function safeMessage(Throwable $e): string
    {
        $line = trim(strtok($e->getMessage(), "\r\n") ?: '');
        if ($line === '') {
            return class_basename($e);
        }

        $secrets = [
            (string) config('database.connections.'.config('database.default').'.password'),
            (string) Env::get('DB_PASSWORD', ''),
        ];
        foreach (array_unique(array_filter($secrets, fn ($s) => $s !== '')) as $secret) {
            $line = str_replace($secret, '***', $line);
        }

        // Mask first, cut afterwards: a cut could otherwise leave a partial password behind.
        return mb_strlen($line) > 300 ? mb_substr($line, 0, 300).'...' : $line;
    }

    /**
     * A SQLite file without any account can be thrown away safely. The installer never deletes it
     * itself; it only prints the exact commands.
     */
    private function printSqliteRecovery(): void
    {
        $path = $this->sqliteFile();
        if ($path === null || ! is_file($path)) {
            return;
        }

        try {
            $db = $this->laravel['db']->connection();
            $hasUsers = $db->getSchemaBuilder()->hasTable('users') && $db->table('users')->exists();
        } catch (Throwable) {
            $hasUsers = false;
        }
        if ($hasUsers) {
            return;
        }

        $real = realpath($path) ?: $path;
        $files = array_values(array_filter([$real, $real.'-journal', $real.'-wal', $real.'-shm'], 'file_exists'));
        $this->line($this->t('sqlite_reset'));
        $this->line('        rm '.implode(' ', array_map('escapeshellarg', $files)));
        $this->line('        cd '.$this->shellArg(base_path()).' && '.$this->shellArg(PHP_BINARY).' artisan threads:install');
    }

    // ---- 5. first admin ----

    private function createAdmin(AdminAccounts $admins): void
    {
        $later = $this->t('admin_later');
        $email = trim((string) $this->option('admin-email'));
        $password = Env::get(self::PASSWORD_ENV);
        $hasEnvPassword = is_string($password) && $password !== '';

        if ($admins->anyAdminExists()) {
            $this->line($this->t('admin_exists'));
            if ($email !== '' && ! $this->isExistingAdmin($admins, $email)) {
                $this->warn($this->t('admin_email_ignored', ['email' => $email]));
            }

            return;
        }

        if ($email === '') {
            if (! $this->input->isInteractive()) {
                if ($hasEnvPassword) {
                    $this->fail5($this->t('admin_env_without_email', ['env' => self::PASSWORD_ENV]), $later);
                } else {
                    $this->warn($this->t('admin_none', ['later' => $later]));
                }

                return;
            }
            if (! $this->confirm($this->t('admin_confirm'), true)) {
                $this->warn($this->t('admin_none', ['later' => $later]));

                return;
            }
            $email = $this->askEmail($admins);
            if ($email === null) {
                $this->fail5($this->t('admin_no_email', ['n' => self::MAX_ATTEMPTS]), $later);

                return;
            }
        } else {
            $email = $admins->normalizeEmail($email);
            if ($error = $admins->emailError($email, $this->locale)) {
                $this->fail5($error, $later);

                return;
            }
        }

        if ($hasEnvPassword) {
            if ($error = $admins->passwordError($password, $this->locale)) {
                $this->fail5(self::PASSWORD_ENV.': '.$error, $later);

                return;
            }
        } elseif ($this->input->isInteractive()) {
            $password = $this->askPassword($admins);
            if ($password === null) {
                $this->fail5($this->t('admin_no_password', ['n' => self::MAX_ATTEMPTS]), $later);

                return;
            }
        } else {
            $this->fail5($this->t('admin_no_password_env', ['env' => self::PASSWORD_ENV]), $later);

            return;
        }

        $admins->createOrReset($email, $password);
        $this->line($this->t('admin_created', ['email' => $email]));
    }

    private function isExistingAdmin(AdminAccounts $admins, string $email): bool
    {
        try {
            return User::query()->where('email', $admins->normalizeEmail($email))->where('is_admin', true)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function askEmail(AdminAccounts $admins): ?string
    {
        for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++) {
            $email = $admins->normalizeEmail((string) $this->ask($this->t('admin_email_prompt')));
            $error = $admins->emailError($email, $this->locale);
            if (! $error) {
                return $email;
            }
            $this->error("      {$error}".($i < self::MAX_ATTEMPTS ? $this->t('try_again') : ''));
        }

        return null;
    }

    private function askPassword(AdminAccounts $admins): ?string
    {
        for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++) {
            $again = $i < self::MAX_ATTEMPTS ? $this->t('try_again') : '';
            $password = (string) $this->secret((string) trans('cli.admin.password_prompt', ['min' => AdminAccounts::MIN_PASSWORD_LENGTH], $this->locale));
            if ($error = $admins->passwordError($password, $this->locale)) {
                $this->error("      {$error}{$again}");

                continue;
            }
            if ($password !== (string) $this->secret((string) trans('cli.admin.confirm_prompt', [], $this->locale))) {
                $this->error('      '.trans('cli.admin.mismatch', [], $this->locale).$again);

                continue;
            }

            return $password;
        }

        return null;
    }

    private function fail5(string $error, string $later): void
    {
        $this->markFailed(5);
        $this->error($this->t('step5_error', ['error' => $error]));
        $this->line("      {$later}");
    }

    // ---- 6. permissions ----

    private function checkWritableDirectories(): void
    {
        $roots = [
            storage_path() => [storage_path(), storage_path('logs'), storage_path('framework'), storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views')],
            $this->laravel->bootstrapPath('cache') => [$this->laravel->bootstrapPath('cache')],
            database_path() => [database_path()],
        ];

        $bad = [];
        foreach ($roots as $root => $dirs) {
            foreach ($dirs as $dir) {
                if (is_dir($dir) && ! is_writable($dir)) {
                    $bad[] = $root;
                    break;
                }
                if (! is_dir($dir) && $dir === $root) {
                    $bad[] = $root;
                    break;
                }
            }
        }

        // The SQLite file itself must be writable too (directories alone are not enough).
        $file = $this->sqliteFile();
        if ($file !== null && is_file($file) && ! is_writable($file)
            && ! in_array(realpath(dirname($file)), array_map('realpath', $bad), true)) {
            $bad[] = $file;
        }

        $user = $this->currentUser();
        if ($bad === []) {
            $this->line($this->t('writable_ok', ['user' => $user]));
            $this->line($this->t('writable_hint'));

            return;
        }

        $this->markFailed(6);
        $this->error($this->t('not_writable', ['user' => $user]));
        foreach ($bad as $dir) {
            $this->line("      - {$dir}");
        }
        $this->printFixCommands($bad);
    }

    /** @param  list<string>  $dirs */
    private function printFixCommands(array $dirs): void
    {
        $user = $this->currentUser();
        $paths = implode(' ', array_map('escapeshellarg', $dirs));
        $foreign = array_filter($dirs, fn (string $dir) => $this->ownerOf($dir) !== null && $this->ownerOf($dir) !== $user);

        $this->line($this->t('fix_with'));
        if ($foreign !== []) {
            $this->line("        sudo chown -R {$user} ".implode(' ', array_map('escapeshellarg', $foreign)));
        }
        if ($this->webGroupExists()) {
            // Same model as docs/SELF-HOSTING.md 2.1: your files, group www-data, group-writable, setgid directories.
            $this->line("        sudo chgrp -R www-data {$paths}");
            $this->line("        sudo chmod -R ug+rwX {$paths}");
            $this->line("        sudo find {$paths} -type d -exec chmod g+s {} +");
            $this->line($this->t('www_data_note'));
        } else {
            // Laptop / macOS local try: no web server group, only you need to write.
            $this->line('        '.($foreign !== [] ? 'sudo ' : '')."chmod -R u+w {$paths}");
        }
        $this->line($this->t('run_again'));
    }

    private function webGroupExists(): bool
    {
        if (self::$groupExists !== null) {
            return (self::$groupExists)('www-data');
        }
        if (function_exists('posix_getgrnam')) {
            return posix_getgrnam('www-data') !== false;
        }

        return is_readable('/etc/group') && preg_match('/^www-data:/m', (string) @file_get_contents('/etc/group')) === 1;
    }

    private function ownerOf(string $path): ?string
    {
        $uid = @fileowner($path);
        if ($uid === false || ! function_exists('posix_getpwuid')) {
            return null;
        }
        $info = posix_getpwuid($uid);

        return is_array($info) ? ($info['name'] ?? null) : null;
    }

    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && ($info['name'] ?? '') !== '') {
                return $info['name'];
            }
        }

        return get_current_user() ?: 'your-user';
    }

    // ---- 7. summary / next steps ----

    private function finish(bool $reachedEnd): int
    {
        $this->newLine();
        if ($this->failedSteps === []) {
            $this->info($this->t('done'));
            $this->printNextSteps();
            $this->warnIfNoAdmin();

            return self::SUCCESS;
        }

        sort($this->failedSteps);
        $n = count($this->failedSteps);
        $this->error((string) trans_choice('cli.install.failed_steps', $n, ['steps' => implode(', ', $this->failedSteps)], $this->locale));
        if ($reachedEnd) {
            $this->line($this->t('remaining'));
            $this->printNextSteps();
            $this->warnIfNoAdmin();
        }

        return self::FAILURE;
    }

    /**
     * A non-interactive run can finish without any admin (no --admin-email). The exit code stays 0
     * (setup itself worked), but the summary says plainly that nobody can sign in yet.
     */
    private function warnIfNoAdmin(): void
    {
        if ($this->input->isInteractive()) {
            return;
        }
        try {
            $none = ! app(AdminAccounts::class)->anyAdminExists();
        } catch (Throwable) {
            return;
        }
        if ($none) {
            $this->warn($this->t('no_admin_warning'));
        }
    }

    private function printNextSteps(): void
    {
        $this->line($this->t('next_app_url'));
        $this->line($this->t('next_cron'));
        $this->line('     '.$this->cronLine());
        $this->line($this->t('next_nginx', ['path' => base_path('docs/deploy/nginx.conf.example')]));
        $this->line($this->t('next_serve'));
    }

    /**
     * A line of installer output in the chosen language (lang/…/cli.php, "install.*"). The locale is
     * passed explicitly: reloading the configuration mid-run must not change the language.
     *
     * @param  array<string, string|int>  $params
     */
    private function t(string $key, array $params = []): string
    {
        return (string) trans('cli.install.'.$key, $params, $this->locale);
    }

    /** Uses the absolute path of the PHP running the installer: cron's plain `php` may be another version. */
    private function cronLine(): string
    {
        return '* * * * * cd '.$this->shellArg(base_path()).' && '.$this->shellArg(PHP_BINARY).' artisan schedule:run >> /dev/null 2>&1';
    }

    /** Quote only when needed, so ordinary paths stay readable. */
    private function shellArg(string $value): string
    {
        return preg_match('#^[A-Za-z0-9_./+:@=-]+$#', $value) === 1 ? $value : escapeshellarg($value);
    }

    /**
     * The installer always manages ".env" itself, never an environment-specific file such as
     * ".env.testing" (which Laravel would otherwise pick when APP_ENV matches).
     */
    private function envFile(): string
    {
        return $this->laravel->environmentPath().DIRECTORY_SEPARATOR.'.env';
    }
}
