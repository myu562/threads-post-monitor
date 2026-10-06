<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Contract G6: a password reset from threads:create-admin signs the account out everywhere, like /users. */
class CreateAdminSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function sessionRow(string $id, ?int $userId): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
    }

    private function reset(string $email, string $password): void
    {
        $this->artisan('threads:create-admin', ['email' => $email, '--lang' => 'en'])
            ->expectsQuestion('Password (min 12 characters)', $password)
            ->expectsQuestion('Confirm password', $password)
            ->assertSuccessful();
    }

    public function test_reset_deletes_the_users_sessions_and_rotates_the_remember_token(): void
    {
        config(['session.driver' => 'database']);
        $boss = User::create(['name' => 'Boss', 'email' => 'boss@example.test', 'password' => 'correct-horse-battery']);
        $boss->forceFill(['remember_token' => 'old-remember-token'])->save();
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'brand-new-password']);
        $other->forceFill(['remember_token' => 'others-token'])->save();
        $this->sessionRow('boss-1', $boss->id);
        $this->sessionRow('boss-2', $boss->id);
        $this->sessionRow('other-1', $other->id);
        $this->sessionRow('guest-1', null);

        $this->reset('boss@example.test', 'a-long-password-1');

        $this->assertSame(['guest-1', 'other-1'], DB::table('sessions')->orderBy('id')->pluck('id')->all());
        $boss->refresh();
        $this->assertNotSame('old-remember-token', $boss->getRememberToken());
        $this->assertSame(60, strlen((string) $boss->getRememberToken()));
        $this->assertTrue(Hash::check('a-long-password-1', $boss->password));
        $this->assertSame('others-token', $other->fresh()->getRememberToken());
    }

    public function test_creating_a_new_admin_touches_no_sessions(): void
    {
        config(['session.driver' => 'database']);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'brand-new-password']);
        $this->sessionRow('other-1', $other->id);

        $this->reset('new@example.test', 'a-long-password-1');

        $this->assertSame(['other-1'], DB::table('sessions')->pluck('id')->all());
        $this->assertEmpty(User::where('email', 'new@example.test')->sole()->getRememberToken());
    }

    public function test_other_session_drivers_still_rotate_the_remember_token(): void
    {
        config(['session.driver' => 'file']);
        $boss = User::create(['name' => 'Boss', 'email' => 'boss@example.test', 'password' => 'correct-horse-battery']);
        $boss->forceFill(['remember_token' => 'old-remember-token'])->save();
        $this->sessionRow('boss-1', $boss->id); // left alone: that table is not the session store here

        $this->reset('boss@example.test', 'a-long-password-1');

        $this->assertNotSame('old-remember-token', $boss->fresh()->getRememberToken());
        $this->assertSame(1, DB::table('sessions')->count());
    }
}
