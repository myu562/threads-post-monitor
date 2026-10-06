<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private const PW = 'correct-horse-battery';

    private function user(): User
    {
        return User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => self::PW]);
    }

    public function test_page_renders_for_any_logged_in_user(): void
    {
        $this->actingAs($this->user())->get('/account/password')->assertOk()->assertSee('目前密碼')->assertSee('修改密碼');
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $u = $this->user();
        $this->actingAs($u)->from('/account/password')->put('/account/password', [
            'current_password' => 'not-my-password', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertRedirect('/account/password')->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check(self::PW, $u->fresh()->password));
    }

    public function test_new_password_must_be_12_chars_and_confirmed(): void
    {
        $u = $this->user();
        $this->actingAs($u)->from('/account/password')->put('/account/password', [
            'current_password' => self::PW, 'password' => 'short-pw', 'password_confirmation' => 'short-pw',
        ])->assertSessionHasErrors('password');
        $this->actingAs($u)->from('/account/password')->put('/account/password', [
            'current_password' => self::PW, 'password' => 'brand-new-password', 'password_confirmation' => 'different-password',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::PW, $u->fresh()->password));
    }

    public function test_success_then_login_with_new_password(): void
    {
        $u = $this->user();
        $this->actingAs($u)->put('/account/password', [
            'current_password' => self::PW, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertRedirect('/account/password')->assertSessionHas('status', '密碼已更新。');

        $this->post('/logout');
        $this->assertGuest();

        $this->from('/login')->post('/login', ['email' => 'u@example.test', 'password' => self::PW])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'u@example.test', 'password' => 'brand-new-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
    }
}
