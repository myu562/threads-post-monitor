<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * The Eloquent user provider with equal work for every failed login.
 *
 * SessionGuard::attempt() runs retrieveByCredentials() and validateCredentials() inside its
 * Timebox. Laravel only checks the password when the email exists, so an unknown email used to
 * answer faster. Here an unknown email is checked against a dummy hash of the same cost, and a
 * deactivated account fails after its real password check: unknown email, deactivated account
 * and wrong password each cost exactly one hash check, all inside the same Timebox.
 */
class ThreadsUserProvider extends EloquentUserProvider
{
    /** @var array<string, string> dummy hash per cost */
    private static array $dummy = [];

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        $user = parent::retrieveByCredentials($credentials);

        if ($user === null && isset($credentials['password']) && is_string($credentials['password'])) {
            // No such user: spend the same hash work validateCredentials() would.
            $this->hasher->check($credentials['password'], $this->dummyHash());
        }

        return $user;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
    {
        $valid = parent::validateCredentials($user, $credentials);

        // Checked after the hash, so a deactivated account costs the same as any other failure.
        return $valid && (bool) ($user->is_active ?? true);
    }

    /** A well-formed hash with the configured cost; it never matches a real password. */
    private function dummyHash(): string
    {
        if (config('hashing.driver', 'bcrypt') === 'bcrypt') {
            $cost = max(4, min(31, (int) config('hashing.bcrypt.rounds', 12)));
            // 22 salt chars + 31 hash chars from the bcrypt alphabet.
            return self::$dummy['bcrypt'.$cost] ??= sprintf('$2y$%02d$', $cost).str_repeat('A', 53);
        }

        $key = (string) config('hashing.driver');

        return self::$dummy[$key] ??= $this->hasher->make(Str::random(40));
    }
}
