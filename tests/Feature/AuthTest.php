<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'correct-horse-battery']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in');
    }

    public function test_no_register_route(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
    }

    public function test_admin_can_log_in_and_out(): void
    {
        $this->admin();

        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/');
        $this->assertAuthenticated();
        $this->get('/')->assertOk()->assertSee(config('app.name'));

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->admin();

        $this->from('/login')->post('/login', ['email' => 'admin@example.test', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        $this->admin();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'admin@example.test', 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'correct-horse-battery'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_create_admin_command_creates_and_resets(): void
    {
        $this->artisan('threads:create-admin', ['email' => 'Boss@Example.test'])
            ->expectsQuestion('Password (min 12 characters)', 'a-long-password-1')
            ->expectsQuestion('Confirm password', 'a-long-password-1')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'boss@example.test']);

        $this->artisan('threads:create-admin', ['email' => 'boss@example.test'])
            ->expectsQuestion('Password (min 12 characters)', 'another-password-2')
            ->expectsQuestion('Confirm password', 'another-password-2')
            ->assertSuccessful();

        $this->assertSame(1, User::count());
        $boss = User::sole();
        $this->assertTrue($boss->is_admin);
        $this->assertTrue($boss->is_active);
        $this->post('/login', ['email' => 'boss@example.test', 'password' => 'another-password-2'])->assertRedirect('/');
    }

    public function test_create_admin_rejects_short_or_mismatched_password(): void
    {
        $this->artisan('threads:create-admin', ['email' => 'boss@example.test'])
            ->expectsQuestion('Password (min 12 characters)', 'short')
            ->assertFailed();

        $this->artisan('threads:create-admin', ['email' => 'boss@example.test'])
            ->expectsQuestion('Password (min 12 characters)', 'a-long-password-1')
            ->expectsQuestion('Confirm password', 'different-password')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
