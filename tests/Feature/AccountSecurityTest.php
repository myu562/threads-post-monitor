<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** FX-A: V3-F1 (last-admin race), V3-F2 (sessions), V3-F4 (XSS), V3-F7 (user id enumeration). */
class AccountSecurityTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private const PW = 'correct-horse-battery';

    private const LAST_ADMIN = '系統至少要保留一位啟用中的管理員。';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function makeUser(string $email, bool $admin = false, bool $active = true): User
    {
        $u = User::create(['name' => 'u', 'email' => $email, 'password' => self::PW]);
        $u->forceFill(['is_admin' => $admin, 'is_active' => $active, 'remember_token' => 'old-remember-token'])->save();

        return $u->fresh();
    }

    private function form(User $u, array $over = []): array
    {
        return array_merge([
            'name' => $u->name, 'email' => $u->email, 'password' => '', 'password_confirmation' => '',
            'is_admin' => $u->is_admin ? '1' : '0', 'is_active' => $u->is_active ? '1' : '0',
        ], $over);
    }

    // ---- V3-F1: the last-active-admin check and the write are one transaction ----

    /**
     * Simulate the race: between our guard check and our write, a concurrent request deactivates the
     * only other active admin. The recount after the write sees zero active admins and rolls back.
     */
    public function test_concurrent_demotion_is_rolled_back_by_the_recount_after_write(): void
    {
        $a = $this->makeUser('a@example.test', admin: true);
        $b = $this->makeUser('b@example.test', admin: true);
        User::saving(function (User $u) use ($a, $b) {
            if ($u->id === $b->id && ! $u->is_admin) {
                DB::table('users')->where('id', $a->id)->update(['is_active' => false]); // the "other" request
            }
        });

        $this->actingAs($a)->from('/users')->put("/users/{$b->id}", $this->form($b, ['is_admin' => '0']))
            ->assertRedirect('/users')
            ->assertSessionHasErrors(['guard' => self::LAST_ADMIN]);

        $this->assertTrue($b->fresh()->is_admin);
        $this->assertTrue($b->fresh()->is_active);
        $this->assertTrue($a->fresh()->is_active); // the simulated concurrent write was inside our rolled-back transaction
    }

    public function test_concurrent_delete_is_rolled_back_by_the_recount_after_write(): void
    {
        $a = $this->makeUser('a@example.test', admin: true);
        $b = $this->makeUser('b@example.test', admin: true);
        User::deleting(function (User $u) use ($a, $b) {
            if ($u->id === $b->id) {
                DB::table('users')->where('id', $a->id)->update(['is_active' => false]);
            }
        });

        $this->actingAs($a)->from('/users')->delete("/users/{$b->id}")
            ->assertRedirect('/users')
            ->assertSessionHasErrors(['guard' => self::LAST_ADMIN]);

        $this->assertNotNull($b->fresh());
    }

    public function test_password_is_hashed_before_the_transaction_starts(): void
    {
        $a = $this->makeUser('a@example.test', admin: true);
        $b = $this->makeUser('b@example.test');
        $hasher = new class($this->app) extends \Illuminate\Hashing\HashManager
        {
            public int $baseLevel = 0;

            public bool $hashedInsideTransaction = false;

            public function make($value, array $options = [])
            {
                if (DB::transactionLevel() > $this->baseLevel) {
                    $this->hashedInsideTransaction = true;
                }

                return parent::make($value, $options);
            }
        };
        $hasher->baseLevel = DB::transactionLevel(); // RefreshDatabase already wraps the test in one
        Hash::swap($hasher);

        $this->actingAs($a)->put("/users/{$b->id}", $this->form($b, [
            'password' => 'brand-new-password-1', 'password_confirmation' => 'brand-new-password-1',
        ]))->assertRedirect('/users');

        $this->assertFalse($hasher->hashedInsideTransaction);
        $this->assertTrue(Hash::check('brand-new-password-1', $b->fresh()->password));
    }

    // ---- V3-F2: sessions ----

    public function test_auth_routes_use_authenticate_session(): void
    {
        $u = $this->makeUser('u@example.test');

        // A session pinned to an old password hash is signed out on its next request.
        $this->actingAs($u)->withSession(['password_hash_web' => 'stale-hash'])
            ->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_own_password_change_logs_out_other_devices(): void
    {
        Event::fake([OtherDeviceLogout::class]);
        $u = $this->makeUser('u@example.test');

        $this->actingAs($u)->put('/account/password', [
            'current_password' => self::PW, 'password' => 'brand-new-password-1', 'password_confirmation' => 'brand-new-password-1',
        ])->assertRedirect('/account/password');

        Event::assertDispatched(OtherDeviceLogout::class, fn ($e) => $e->user->is($u));
        $this->assertTrue(Hash::check('brand-new-password-1', $u->fresh()->password));
        // This session keeps working.
        $this->get('/')->assertOk();
    }

    private function seedSessions(User $target, User $other): void
    {
        config(['session.driver' => 'database']);
        foreach ([['t1', $target->id], ['t2', $target->id], ['o1', $other->id]] as [$id, $uid]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $uid, 'ip_address' => null, 'user_agent' => null,
                'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
    }

    public function test_admin_password_reset_ends_target_sessions_and_cycles_remember_token(): void
    {
        $admin = $this->makeUser('admin@example.test', admin: true);
        $target = $this->makeUser('t@example.test');
        $this->seedSessions($target, $admin);

        $this->actingAs($admin)->put("/users/{$target->id}", $this->form($target, [
            'password' => 'brand-new-password-1', 'password_confirmation' => 'brand-new-password-1',
        ]))->assertRedirect('/users');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertTrue(DB::table('sessions')->where('id', 'o1')->exists()); // other users untouched
        $this->assertNotSame('old-remember-token', $target->fresh()->remember_token);
    }

    public function test_admin_deactivation_ends_target_sessions_and_cycles_remember_token(): void
    {
        $admin = $this->makeUser('admin@example.test', admin: true);
        $target = $this->makeUser('t@example.test');
        $this->seedSessions($target, $admin);

        $this->actingAs($admin)->put("/users/{$target->id}", $this->form($target, ['is_active' => '0']))
            ->assertRedirect('/users');

        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertNotSame('old-remember-token', $target->fresh()->remember_token);
    }

    public function test_plain_edit_keeps_target_sessions(): void
    {
        $admin = $this->makeUser('admin@example.test', admin: true);
        $target = $this->makeUser('t@example.test');
        $this->seedSessions($target, $admin);

        $this->actingAs($admin)->put("/users/{$target->id}", $this->form($target, ['name' => 'Renamed']))
            ->assertRedirect('/users');

        $this->assertSame(2, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame('old-remember-token', $target->fresh()->remember_token);
    }

    public function test_admin_resetting_own_password_stays_logged_in(): void
    {
        $admin = $this->makeUser('admin@example.test', admin: true);

        $this->actingAs($admin)->put("/users/{$admin->id}", $this->form($admin, [
            'password' => 'brand-new-password-1', 'password_confirmation' => 'brand-new-password-1',
        ]))->assertRedirect('/users');

        $this->get('/users')->assertOk();
    }

    // ---- V3-F7: no user-id probing ----

    public function test_non_admin_gets_403_for_existing_and_missing_user_ids(): void
    {
        $u = $this->makeUser('u@example.test');
        $other = $this->makeUser('o@example.test');
        $this->actingAs($u);

        foreach ([$other->id, 999999] as $id) {
            $this->get("/users/{$id}/edit")->assertForbidden();
            $this->put("/users/{$id}", ['name' => 'x'])->assertForbidden();
            $this->delete("/users/{$id}")->assertForbidden();
        }
    }

    public function test_admin_still_gets_404_for_missing_user(): void
    {
        $this->actingAs($this->makeUser('admin@example.test', admin: true))
            ->get('/users/999999/edit')->assertNotFound();
    }

    // ---- V3-F4: values inside JavaScript strings ----

    public function test_delete_confirm_escapes_email_for_javascript(): void
    {
        $admin = $this->makeUser('admin@example.test', admin: true);
        $evil = User::create(['name' => 'x', 'email' => "x');alert(1);//@example.test", 'password' => self::PW]);

        $html = $this->actingAs($admin)->get('/users')->assertOk()->getContent();

        $this->assertStringNotContainsString("x');alert(1)", $html);
        // The email never reaches JavaScript source: it sits HTML-escaped in data-confirm, and the
        // inline handler is a constant that reads it from the form's dataset.
        $this->assertMatchesRegularExpression('/data-confirm="[^"]*x&#039;\);alert\(1\);\/\/@example\.test[^"]*" onsubmit="return confirm\(this\.dataset\.confirm\);"/', $html);
        $this->assertDoesNotMatchRegularExpression('/onsubmit="[^"]*example\.test/', $html);
        $this->assertNotNull($evil->id);
    }

    public function test_no_blade_echo_inside_inline_event_handlers_or_scripts(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $info) {
            if (! str_ends_with($info->getFilename(), '.blade.php')) {
                continue;
            }
            $file = $info->getPathname();
            $src = file_get_contents($file);
            $checked++;
            $this->assertDoesNotMatchRegularExpression('/\bon[a-z]+="[^"]*\{\{/', $src, $file);
            $this->assertDoesNotMatchRegularExpression('/\bon[a-z]+="[^"]*\{!!/', $src, $file);
            if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $src, $m)) {
                foreach ($m[1] as $body) {
                    $this->assertDoesNotMatchRegularExpression('/\{\{|\{!!/', $body, $file);
                }
            }
        }
        $this->assertGreaterThan(5, $checked);
    }
}
