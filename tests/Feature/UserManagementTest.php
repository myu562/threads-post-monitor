<?php

namespace Tests\Feature;

use App\Models\PostSnapshot;
use App\Models\TrackedPost;
use App\Models\User;
use App\Threads\TrackPostService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private const PW = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function makeUser(string $email, bool $admin = false, bool $active = true): User
    {
        $u = User::create(['name' => strtok($email, '@'), 'email' => $email, 'password' => self::PW]);
        $u->forceFill(['is_admin' => $admin, 'is_active' => $active])->save();

        return $u->fresh();
    }

    private function admin(): User
    {
        return $this->makeUser('admin@example.test', admin: true);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Carol', 'email' => 'carol@example.test',
            'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1',
            'is_admin' => '0', 'is_active' => '1',
        ], $over);
    }

    // ---- access ----

    public function test_non_admin_gets_403_everywhere(): void
    {
        $u = $this->makeUser('u@example.test');
        $other = $this->makeUser('o@example.test');
        $this->actingAs($u);

        $this->get('/users')->assertForbidden();
        $this->get('/users/create')->assertForbidden();
        $this->post('/users', $this->payload())->assertForbidden();
        $this->get("/users/{$other->id}/edit")->assertForbidden();
        $this->put("/users/{$other->id}", $this->payload(['email' => 'o@example.test']))->assertForbidden();
        $this->delete("/users/{$other->id}")->assertForbidden();

        $this->assertSame(2, User::count());
        $this->get('/')->assertOk()->assertDontSee('帳號管理');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/users')->assertRedirect('/login');
        $this->get('/account/password')->assertRedirect('/login');
    }

    public function test_admin_sees_nav_and_user_list(): void
    {
        $admin = $this->admin();
        $u = $this->makeUser('u@example.test', active: false);
        $p = TrackedPost::create(['url' => 'x', 'shortcode' => 'DFakeP0st01']);
        $u->trackedPosts()->attach($p->id);

        $this->actingAs($admin)->get('/')->assertSee('帳號管理')->assertSee('修改密碼')->assertSee('追蹤清單');
        $res = $this->actingAs($admin)->get('/users')->assertOk()
            ->assertSee('admin@example.test')->assertSee('u@example.test')
            ->assertSee('停用')->assertSee('啟用')->assertSee('追蹤中貼文數')->assertSee('建立時間');
        $this->assertMatchesRegularExpression('/data-user="'.$u->id.'".*?<td>1<\/td>/s', $res->getContent());
    }

    // ---- create / edit / deactivate / delete ----

    public function test_admin_can_create_user_with_lowercased_email(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', $this->payload(['email' => '  Carol@Example.TEST ', 'is_admin' => '1']))
            ->assertRedirect('/users');

        $carol = User::where('email', 'carol@example.test')->sole();
        $this->assertTrue($carol->is_admin);
        $this->assertTrue($carol->is_active);
        $this->assertTrue(Hash::check('a-long-password-1', $carol->password));
    }

    public function test_create_validates_password_and_unique_email(): void
    {
        $admin = $this->actingAs($this->admin());

        $this->from('/users/create')->post('/users', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
        $this->from('/users/create')->post('/users', $this->payload(['password_confirmation' => 'something-else-123']))
            ->assertSessionHasErrors('password');
        $this->from('/users/create')->post('/users', $this->payload(['email' => 'ADMIN@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_admin_can_edit_user_and_reset_password(): void
    {
        $u = $this->makeUser('u@example.test');

        $this->actingAs($this->admin())->put("/users/{$u->id}", $this->payload([
            'name' => 'New Name', 'email' => 'NEW@example.test', 'is_admin' => '1', 'is_active' => '1',
            'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ]))->assertRedirect('/users');

        $u->refresh();
        $this->assertSame('New Name', $u->name);
        $this->assertSame('new@example.test', $u->email);
        $this->assertTrue($u->is_admin);
        $this->assertTrue(Hash::check('brand-new-password', $u->password));
    }

    public function test_edit_with_blank_password_keeps_old_password(): void
    {
        $u = $this->makeUser('u@example.test');
        $this->actingAs($this->admin())->put("/users/{$u->id}", $this->payload([
            'email' => 'u@example.test', 'password' => '', 'password_confirmation' => '',
        ]))->assertRedirect('/users');

        $this->assertTrue(Hash::check(self::PW, $u->fresh()->password));
    }

    public function test_edit_rejects_email_taken_by_other_user(): void
    {
        $u = $this->makeUser('u@example.test');
        $this->actingAs($this->admin())->from("/users/{$u->id}/edit")
            ->put("/users/{$u->id}", $this->payload(['email' => 'Admin@Example.test', 'password' => '', 'password_confirmation' => '']))
            ->assertSessionHasErrors('email');
        $this->assertSame('u@example.test', $u->fresh()->email);
    }

    public function test_admin_can_deactivate_and_delete_user(): void
    {
        $u = $this->makeUser('u@example.test');
        $admin = $this->admin();

        $this->actingAs($admin)->put("/users/{$u->id}", $this->payload([
            'email' => 'u@example.test', 'password' => '', 'password_confirmation' => '', 'is_active' => '0',
        ]))->assertRedirect('/users');
        $this->assertFalse($u->fresh()->is_active);

        $this->actingAs($admin)->delete("/users/{$u->id}")->assertRedirect('/users');
        $this->assertNull(User::find($u->id));
    }

    // ---- C7 guards ----

    public function test_cannot_deactivate_self(): void
    {
        $admin = $this->admin();
        $this->makeUser('other-admin@example.test', admin: true);

        $this->actingAs($admin)->from("/users/{$admin->id}/edit")->put("/users/{$admin->id}", $this->payload([
            'email' => 'admin@example.test', 'password' => '', 'password_confirmation' => '', 'is_admin' => '1', 'is_active' => '0',
        ]))->assertSessionHasErrors('guard');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_cannot_remove_own_admin(): void
    {
        $admin = $this->admin();
        $this->makeUser('other-admin@example.test', admin: true);

        $this->actingAs($admin)->from("/users/{$admin->id}/edit")->put("/users/{$admin->id}", $this->payload([
            'email' => 'admin@example.test', 'password' => '', 'password_confirmation' => '', 'is_admin' => '0', 'is_active' => '1',
        ]))->assertSessionHasErrors('guard');

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_cannot_delete_self(): void
    {
        $admin = $this->admin();
        $this->makeUser('other-admin@example.test', admin: true);

        $this->actingAs($admin)->from('/users')->delete("/users/{$admin->id}")->assertSessionHasErrors('guard');
        $this->assertNotNull(User::find($admin->id));
    }

    /**
     * The acting admin is always an active admin (middleware), so the "keep one active admin" guard
     * only matters when the actor's own row changed concurrently (e.g. another admin deactivated it
     * mid-session). Simulate that: the in-memory actor is active, its DB row is not.
     */
    public function test_last_active_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $actor = $this->admin();
        $last = $this->makeUser('last@example.test', admin: true);
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);

        $this->actingAs($actor);
        $base = ['email' => 'last@example.test', 'password' => '', 'password_confirmation' => ''];

        $this->from('/users')->put("/users/{$last->id}", $this->payload($base + ['is_admin' => '0', 'is_active' => '1']))
            ->assertSessionHasErrors('guard');
        $this->from('/users')->put("/users/{$last->id}", $this->payload($base + ['is_admin' => '1', 'is_active' => '0']))
            ->assertSessionHasErrors('guard');
        $this->from('/users')->delete("/users/{$last->id}")->assertSessionHasErrors('guard');

        $last->refresh();
        $this->assertTrue($last->is_admin);
        $this->assertTrue($last->is_active);
    }

    public function test_demoting_another_admin_is_allowed_when_another_active_admin_remains(): void
    {
        $actor = $this->admin();
        $other = $this->makeUser('other@example.test', admin: true);

        $this->actingAs($actor)->put("/users/{$other->id}", $this->payload([
            'email' => 'other@example.test', 'password' => '', 'password_confirmation' => '', 'is_admin' => '0',
        ]))->assertRedirect('/users');
        $this->assertFalse($other->fresh()->is_admin);
    }

    // ---- deleting a user cleans up orphaned posts ----

    public function test_deleting_user_removes_only_orphaned_posts(): void
    {
        $admin = $this->admin();
        $u = $this->makeUser('u@example.test');
        $keeper = $this->makeUser('k@example.test');
        $service = app(TrackPostService::class);

        $onlyU = $service->register('https://www.threads.com/@a/post/DFakeP0st01', $u);
        $shared = $service->register('https://www.threads.com/@b/post/DFakeP0st02', $u);
        $service->register('https://www.threads.com/@b/post/DFakeP0st02', $keeper);
        foreach ([$onlyU, $shared] as $p) {
            $p->snapshots()->create(['captured_at' => CarbonImmutable::now('UTC'), 'source' => 'public_page', 'views' => 1]);
        }

        $this->actingAs($admin)->delete("/users/{$u->id}")->assertRedirect('/users');

        $this->assertNull(User::find($u->id));
        $this->assertNull(TrackedPost::find($onlyU->id));
        $this->assertNotNull(TrackedPost::find($shared->id));
        $this->assertSame(1, PostSnapshot::count());
        $this->assertSame([$shared->id], $keeper->trackedPosts()->pluck('tracked_posts.id')->all());
        $this->assertSame(1, DB::table('tracked_post_user')->count());
    }

    // ---- C2 mass assignment ----

    public function test_privilege_fields_are_not_mass_assignable(): void
    {
        $u = User::create(['name' => 'x', 'email' => 'x@example.test', 'password' => self::PW, 'is_admin' => true, 'is_active' => false]);
        $u->refresh();
        $this->assertFalse($u->is_admin);
        $this->assertTrue($u->is_active);

        $u->update(['is_admin' => true]);
        $this->assertFalse($u->fresh()->is_admin);
        $this->assertNotContains('is_admin', $u->getFillable());
        $this->assertNotContains('is_active', $u->getFillable());
    }

    public function test_non_admin_cannot_escalate_via_password_form(): void
    {
        $u = $this->makeUser('u@example.test');
        $this->actingAs($u)->put('/account/password', [
            'current_password' => self::PW, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
            'is_admin' => '1',
        ])->assertRedirect('/account/password');
        $this->assertFalse($u->fresh()->is_admin);
    }

    // ---- C8 inactive accounts ----

    public function test_inactive_user_cannot_log_in_with_generic_error(): void
    {
        $this->makeUser('off@example.test', active: false);

        $inactive = $this->from('/login')->post('/login', ['email' => 'off@example.test', 'password' => self::PW]);
        $inactive->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $inactiveMsg = session('errors')->first('email');

        $this->flushSession();
        $unknown = $this->from('/login')->post('/login', ['email' => 'nobody@example.test', 'password' => self::PW]);
        $unknown->assertSessionHasErrors('email');
        $this->assertSame($inactiveMsg, session('errors')->first('email'));
    }

    public function test_logged_in_user_is_logged_out_after_deactivation(): void
    {
        $u = $this->makeUser('u@example.test');
        $this->post('/login', ['email' => 'u@example.test', 'password' => self::PW])->assertRedirect('/');
        $this->get('/')->assertOk();

        DB::table('users')->where('id', $u->id)->update(['is_active' => false]);
        // A real next request re-reads the user from the DB; the test kernel caches it on the guard.
        $this->app['auth']->forgetGuards();

        $this->get('/')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_email_is_case_insensitive(): void
    {
        $this->makeUser('u@example.test');
        $this->post('/login', ['email' => 'U@Example.test', 'password' => self::PW])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    // ---- C9 CLI ----

    public function test_create_admin_cli_forces_admin_and_active(): void
    {
        $u = $this->makeUser('boss@example.test', admin: false, active: false);

        $this->artisan('threads:create-admin', ['email' => 'boss@example.test'])
            ->expectsQuestion('Password (min 12 characters)', 'a-long-password-1')
            ->expectsQuestion('Confirm password', 'a-long-password-1')
            ->assertSuccessful();

        $u->refresh();
        $this->assertTrue($u->is_admin);
        $this->assertTrue($u->is_active);
    }

    // ---- C1 migration data step ----

    public function test_migration_makes_existing_users_admin_and_keeps_their_posts(): void
    {
        // Roll back 000005 (groups), 000004 (caption / tags / notes) and 000003 (ownership).
        $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

        $uid = DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now()]);
        $pid = DB::table('tracked_posts')->insertGetId(['url' => 'u', 'shortcode' => 'DFakeP0st01',
            'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('migrate')->assertSuccessful();

        $owner = User::find($uid);
        $this->assertTrue($owner->is_admin);
        $this->assertTrue($owner->is_active);
        $this->assertSame([$pid], $owner->trackedPosts()->pluck('tracked_posts.id')->all());

        $later = $this->makeUser('later@example.test');
        $this->assertFalse($later->is_admin);
    }
}
