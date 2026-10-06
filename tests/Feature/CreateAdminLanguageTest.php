<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** threads:create-admin output language (contract L5): --lang, then LC_ALL / LC_MESSAGES / LANG, then English. */
class CreateAdminLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_lang_zh(): void
    {
        $this->artisan('threads:create-admin', ['email' => 'Boss@Example.test', '--lang' => 'zh'])
            ->expectsQuestion('密碼（至少 12 個字元）', 'a-long-password-1')
            ->expectsQuestion('再輸入一次密碼', 'a-long-password-1')
            ->expectsOutput('已建立管理員 boss@example.test。')
            ->assertSuccessful();

        $this->artisan('threads:create-admin', ['email' => 'boss@example.test', '--lang' => 'zh_TW'])
            ->expectsQuestion('密碼（至少 12 個字元）', 'another-password-2')
            ->expectsQuestion('再輸入一次密碼', 'another-password-2')
            ->expectsOutput('已更新 boss@example.test 的密碼。')
            ->assertSuccessful();
        $this->assertSame(1, User::count());
    }

    public function test_zh_errors(): void
    {
        $this->artisan('threads:create-admin', ['email' => 'not-an-email', '--lang' => 'zh'])
            ->expectsOutput('Email 格式不正確。')
            ->assertFailed();

        $this->artisan('threads:create-admin', ['email' => 'a@example.test', '--lang' => 'zh'])
            ->expectsQuestion('密碼（至少 12 個字元）', 'short')
            ->expectsOutput('密碼至少要 12 個字元。')
            ->assertFailed();

        $this->artisan('threads:create-admin', ['email' => 'a@example.test', '--lang' => 'zh'])
            ->expectsQuestion('密碼（至少 12 個字元）', 'a-long-password-1')
            ->expectsQuestion('再輸入一次密碼', 'a-long-password-2')
            ->expectsOutput('兩次輸入的密碼不一致。')
            ->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_environment_variables_in_posix_order(): void
    {
        putenv('LANG=zh_TW.UTF-8');
        $this->artisan('threads:create-admin', ['email' => 'not-an-email'])->expectsOutput('Email 格式不正確。')->assertFailed();

        putenv('LC_MESSAGES=en_US.UTF-8'); // more specific than LANG
        $this->artisan('threads:create-admin', ['email' => 'not-an-email'])->expectsOutput('Invalid email address.')->assertFailed();

        putenv('LC_ALL=zh_TW.UTF-8'); // overrides both
        $this->artisan('threads:create-admin', ['email' => 'not-an-email'])->expectsOutput('Email 格式不正確。')->assertFailed();

        $this->artisan('threads:create-admin', ['email' => 'not-an-email', '--lang' => 'en'])->expectsOutput('Invalid email address.')->assertFailed();
    }

    public function test_default_is_english_and_unknown_lang_fails(): void
    {
        $this->artisan('threads:create-admin', ['email' => 'not-an-email'])->expectsOutput('Invalid email address.')->assertFailed();
        $this->artisan('threads:create-admin', ['email' => 'a@example.test', '--lang' => 'de'])
            ->expectsOutput('Unknown --lang value "de". Use zh or en.')
            ->assertFailed();
        $this->assertSame(0, User::count());
    }
}
