<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

/**
 * Shared rules for creating an admin from the command line
 * (threads:create-admin and threads:install use the same checks).
 */
class AdminAccounts
{
    public const MIN_PASSWORD_LENGTH = 12;

    /** Admin password for non-interactive runs of threads:install and threads:create-admin. */
    public const PASSWORD_ENV = 'THREADS_ADMIN_PASSWORD';

    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Returns an error message (in $locale, lang/…/cli.php), or null when the (normalized) email is acceptable. */
    public function emailError(string $email, ?string $locale = null): ?string
    {
        return Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()
            ? (string) trans('cli.admin.invalid_email', [], $locale)
            : null;
    }

    /** Returns an error message (in $locale), or null when the password is acceptable. */
    public function passwordError(string $password, ?string $locale = null): ?string
    {
        return mb_strlen($password) < self::MIN_PASSWORD_LENGTH
            ? (string) trans('cli.admin.short_password', ['min' => self::MIN_PASSWORD_LENGTH], $locale)
            : null;
    }

    public function anyAdminExists(): bool
    {
        return User::where('is_admin', true)->exists();
    }

    /**
     * Create the admin, or reset its password and restore it as an active admin when the email exists.
     * A reset also signs the account out everywhere (sessions and remember-me cookies).
     *
     * @return bool true when the account already existed
     */
    public function createOrReset(string $email, string $password): bool
    {
        $user = User::where('email', $email)->first();
        $existed = $user !== null;
        $user ??= new User(['email' => $email]);
        $user->name = $user->name ?: 'Admin';
        $user->password = Hash::make($password);
        if ($existed) {
            // Same as a password reset at /users: invalidate remember-me cookies, end all sessions.
            $user->setRememberToken(Str::random(60));
        }
        // Privilege fields are not mass assignable: set them explicitly.
        $user->forceFill(['is_admin' => true, 'is_active' => true])->save();

        if ($existed && config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }

        return $existed;
    }
}
