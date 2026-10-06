<?php

namespace App\Console\Commands;

use App\Console\CliLocale;
use App\Services\AdminAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

class CreateAdmin extends Command
{
    protected $signature = 'threads:create-admin {email : Admin login email (without a terminal, the password comes from THREADS_ADMIN_PASSWORD)}
        {--lang= : '.CliLocale::OPTION_HELP.'}';

    protected $description = 'Create an admin user, or reset its password (and re-enable it as an active admin) if the email already exists';

    private string $locale = 'en';

    public function handle(AdminAccounts $admins): int
    {
        $locale = CliLocale::resolve($this->option('lang'));
        if ($locale === null) {
            $this->error((string) trans('cli.bad_lang', ['value' => (string) $this->option('lang')], 'en'));

            return self::FAILURE;
        }
        $this->locale = $locale;

        $email = $admins->normalizeEmail((string) $this->argument('email'));
        if ($error = $admins->emailError($email, $this->locale)) {
            $this->error($error);

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            // No terminal to ask on (cron, CI, --no-interaction): the password comes from the environment.
            $password = Env::get(AdminAccounts::PASSWORD_ENV);
            if (! is_string($password) || $password === '') {
                $this->error($this->t('needs_tty', ['env' => AdminAccounts::PASSWORD_ENV]));

                return self::FAILURE;
            }
            if ($error = $admins->passwordError($password, $this->locale)) {
                $this->error(AdminAccounts::PASSWORD_ENV.': '.$error);

                return self::FAILURE;
            }
        } else {
            $password = (string) $this->secret($this->t('password_prompt', ['min' => AdminAccounts::MIN_PASSWORD_LENGTH]));
            if ($error = $admins->passwordError($password, $this->locale)) {
                $this->error($error);

                return self::FAILURE;
            }
            if ($password !== (string) $this->secret($this->t('confirm_prompt'))) {
                $this->error($this->t('mismatch'));

                return self::FAILURE;
            }
        }

        $existed = $admins->createOrReset($email, $password);

        $this->info($this->t($existed ? 'updated' : 'created', ['email' => $email]));

        return self::SUCCESS;
    }

    /** @param  array<string, string|int>  $params */
    private function t(string $key, array $params = []): string
    {
        return (string) trans('cli.admin.'.$key, $params, $this->locale);
    }
}
