<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** RA-F10: threads:create-admin without a terminal reads THREADS_ADMIN_PASSWORD. */
class CreateAdminNonInteractiveTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->setPasswordEnv(null);
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

    public function test_without_terminal_or_env_it_explains_and_fails(): void
    {
        $code = Artisan::call('threads:create-admin', ['email' => 'boss@example.test', '--no-interaction' => true]);
        $out = Artisan::output();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('An interactive terminal is needed to type the password, or set THREADS_ADMIN_PASSWORD.', $out);
        $this->assertSame(0, User::count());

        Artisan::call('threads:create-admin', ['email' => 'boss@example.test', '--no-interaction' => true, '--lang' => 'zh']);
        $this->assertStringContainsString('需要互動式終端機輸入密碼，或設定 THREADS_ADMIN_PASSWORD。', Artisan::output());
    }

    public function test_env_password_creates_the_admin_without_prompts(): void
    {
        $this->setPasswordEnv('fake-env-admin-pass-77');

        $this->assertSame(0, Artisan::call('threads:create-admin', ['email' => 'Boss@Example.test', '--no-interaction' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('Admin boss@example.test created.', $out);
        $this->assertStringNotContainsString('fake-env-admin-pass-77', $out);
        $user = User::sole();
        $this->assertTrue((bool) $user->is_admin);
        $this->assertTrue(Hash::check('fake-env-admin-pass-77', $user->password));
    }

    public function test_short_env_password_is_rejected(): void
    {
        $this->setPasswordEnv('short');

        $this->assertNotSame(0, Artisan::call('threads:create-admin', ['email' => 'boss@example.test', '--no-interaction' => true]));
        $this->assertStringContainsString('THREADS_ADMIN_PASSWORD: Password must be at least 12 characters.', Artisan::output());
        $this->assertSame(0, User::count());
    }

    public function test_interactive_run_still_prompts_even_with_the_env_set(): void
    {
        $this->setPasswordEnv('fake-env-admin-pass-77');

        $this->artisan('threads:create-admin', ['email' => 'boss@example.test'])
            ->expectsQuestion('Password (min 12 characters)', 'typed-password-12345')
            ->expectsQuestion('Confirm password', 'typed-password-12345')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('typed-password-12345', User::sole()->password));
    }
}
