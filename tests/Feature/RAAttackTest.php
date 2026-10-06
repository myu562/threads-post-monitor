<?php

namespace Tests\Feature;

use App\Http\Controllers\LocaleController;
use App\Models\Group;
use App\Models\Tag;
use App\Models\TrackedPost;
use App\Models\TrackedPostUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** R-A adversarial probes (scratch only). */
class RAAttackTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function user(string $email, bool $admin = false): User
    {
        $u = User::firstOrCreate(['email' => $email], ['name' => 'u', 'password' => 'correct-horse-battery']);
        $u->forceFill(['is_admin' => $admin])->save();

        return $u;
    }

    private function mkpost(array $owners, array $attrs = []): TrackedPost
    {
        $this->seq++;
        $code = 'DFakeRA'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT);
        $p = TrackedPost::create(array_merge([
            'url' => 'https://www.threads.com/t/'.$code, 'shortcode' => $code, 'username' => 'user_'.$this->seq,
            'published_at' => CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'), 'published_at_source' => 'page',
            'track_until' => CarbonImmutable::parse('2026-10-08 17:00:00', 'UTC'), 'status' => 'tracking',
        ], $attrs));
        foreach ($owners as $u) {
            $u->trackedPosts()->attach($p->id);
        }

        return $p;
    }

    private function entry(User $u, TrackedPost $p): TrackedPostUser
    {
        return TrackedPostUser::where('user_id', $u->id)->where('tracked_post_id', $p->id)->sole();
    }

    public function test_idor_on_post_not_in_list(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test', true); // admin too
        $p = $this->mkpost([$a]);
        $this->actingAs($b)->get("/posts/{$p->id}")->assertNotFound();
        $this->actingAs($b)->put("/posts/{$p->id}/meta", ['note' => 'x'])->assertNotFound();
        $this->actingAs($b)->put("/posts/{$p->id}/note", ['note' => 'x'])->assertNotFound();
        $this->actingAs($b)->put("/posts/{$p->id}/tags", ['tags' => 'x'])->assertNotFound();
        $this->actingAs($b)->delete("/posts/{$p->id}")->assertNotFound();
        $this->assertSame(1, TrackedPostUser::count());
        // nonexistent id gives the same 404
        $this->actingAs($b)->get('/posts/999999')->assertNotFound();
    }

    public function test_shared_post_other_users_group_is_rejected(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $p = $this->mkpost([$a, $b]);
        $ga = $a->groups()->create(['name' => 'A secret', 'sort_order' => 1]);
        $r = $this->actingAs($b)->put("/posts/{$p->id}/meta", ['group' => (string) $ga->id, 'note' => 'n']);
        $r->assertStatus(422);
        $this->assertNull($this->entry($b, $p)->group_id);
        $this->assertNull($this->entry($b, $p)->note); // nothing changes
        // also as int-ish forms
        foreach ([' '.$ga->id, $ga->id.' ', '0'.$ga->id, '+'.$ga->id, $ga->id.'.0', '1e0', [$ga->id]] as $v) {
            $code = $this->actingAs($b)->put("/posts/{$p->id}/meta", ['group' => $v])->status();
            $this->assertContains($code, [422, 302], 'value '.json_encode($v));
            $this->assertNull($this->entry($b, $p)->fresh()->group_id, 'value '.json_encode($v));
        }
    }

    public function test_group_routes_idor(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test', true);
        $ga = $a->groups()->create(['name' => 'A secret', 'sort_order' => 1]);
        $this->actingAs($b)->put("/groups/{$ga->id}", ['name' => 'pwn'])->assertNotFound();
        $this->actingAs($b)->delete("/groups/{$ga->id}")->assertNotFound();
        $this->actingAs($b)->post("/groups/{$ga->id}/move", ['direction' => 'up'])->assertNotFound();
        $this->assertSame('A secret', $ga->fresh()->name);
        // filter by other's group: no leak of name
        $this->actingAs($b)->get('/?group='.$ga->id)->assertOk()->assertDontSee('A secret');
    }

    public function test_mass_assignment_on_group_and_meta(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $p = $this->mkpost([$a, $b]);
        $this->actingAs($b)->post('/groups', ['name' => 'G', 'user_id' => $a->id, 'sort_order' => -5, 'id' => 77]);
        $g = Group::sole();
        $this->assertSame($b->id, (int) $g->user_id);
        $this->assertNotSame(77, (int) $g->id);
        $this->actingAs($b)->put("/groups/{$g->id}", ['name' => 'H', 'user_id' => $a->id]);
        $this->assertSame($b->id, (int) $g->fresh()->user_id);
        $this->actingAs($b)->put("/posts/{$p->id}/meta", ['note' => 'n', 'user_id' => $a->id, 'tracked_post_id' => 999, 'group_id' => 5]);
        $e = $this->entry($b, $p);
        $this->assertSame('n', $e->note);
        $this->assertNull($e->group_id);
        $this->assertNull($this->entry($a, $p)->note);
        // users: non-admin creating admin
        $this->actingAs($b)->post('/users', ['name' => 'z', 'email' => 'z@example.test', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery', 'is_admin' => 1])->assertForbidden();
        // own password route can't change is_admin
        $this->actingAs($b)->put('/account/password', ['current_password' => 'correct-horse-battery', 'password' => 'different-password', 'password_confirmation' => 'different-password', 'is_admin' => 1]);
        $this->assertFalse((bool) $b->fresh()->is_admin);
    }

    public function test_search_does_not_match_other_users_note_or_tag(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $p = $this->mkpost([$a, $b]);
        $this->actingAs($a)->put("/posts/{$p->id}/meta", ['note' => 'zebrasecret', 'tags' => 'tigersecret', 'group' => 'new', 'new_group' => 'lionsecret']);
        $html = $this->actingAs($b)->get('/?q=zebrasecret')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-post="'.$p->id.'"', $html);
        $html = $this->actingAs($b)->get('/?q=tigersecret')->getContent();
        $this->assertStringNotContainsString('data-post="'.$p->id.'"', $html);
        $html = $this->actingAs($b)->get('/?tag=tigersecret')->getContent();
        $this->assertStringNotContainsString('data-post="'.$p->id.'"', $html);
        $html = $this->actingAs($b)->get('/posts/'.$p->id)->getContent();
        foreach (['zebrasecret', 'tigersecret', 'lionsecret'] as $s) {
            $this->assertStringNotContainsString($s, $html);
        }
        $html = $this->actingAs($b)->get('/')->getContent();
        foreach (['zebrasecret', 'tigersecret', 'lionsecret'] as $s) {
            $this->assertStringNotContainsString($s, $html);
        }
    }

    public function test_xss_in_group_tag_note_caption_and_flash(): void
    {
        $a = $this->user('a@example.test');
        $p = $this->mkpost([$a], ['caption' => '<img src=x onerror=alert(1)>cap', 'username' => 'u"><svg/onload=alert(2)>']);
        $x = '"><img src=x onerror=alert(9)>';
        $this->actingAs($a)->post('/groups', ['name' => $x]);
        $g = Group::sole();
        $this->actingAs($a)->put("/posts/{$p->id}/meta", ['group' => (string) $g->id, 'tags' => '<b>t</b>', 'note' => '<script>alert(3)</script>']);
        $r = $this->actingAs($a)->post('/groups', ['name' => $x]); // name taken: error with :name
        $pages = [
            $this->actingAs($a)->get('/')->getContent(),
            $this->actingAs($a)->get('/posts/'.$p->id)->getContent(),
            $this->actingAs($a)->withSession(['status' => __('posts.flash.group_created', ['name' => $x])])->get('/')->getContent(),
            $this->actingAs($a)->get('/?q='.urlencode($x).'&tag='.urlencode('<b>t</b>'))->getContent(),
        ];
        foreach ($pages as $i => $html) {
            $this->assertStringNotContainsString('<img src=x', $html, "page $i");
            $this->assertStringNotContainsString('<script>alert', $html, "page $i");
            $this->assertStringNotContainsString('<svg/onload', $html, "page $i");
            $this->assertStringNotContainsString('<b>t</b>', $html, "page $i");
        }
    }

    public function test_safe_redirect_variants(): void
    {
        $bad = ['//evil.test', '/\\evil.test', '\\\\evil.test', 'https://evil.test', "/\t/evil.test", "/\n/evil.test", 'http:evil.test',
            ' /x', '/%09/evil.test', '//%2565vil.test', "/\u{3000}/evil.test", '/x'.str_repeat('a', 3000), 'javascript:alert(1)', '/@evil.test'];
        foreach ($bad as $t) {
            $out = LocaleController::safeRedirect($t);
            $this->assertTrue($out === '/' || (str_starts_with($out, '/') && ! str_starts_with($out, '//') && ! str_contains($out, '\\')), json_encode($t).' -> '.$out);
        }
        // the actual Location header for odd but "allowed" paths stays on this host
        foreach (['/%2F%2Fevil.test', "/\u{3000}/evil.test", '/@evil.test', '/./evil.test', '/..//evil.test', '/;//evil.test'] as $t) {
            $loc = $this->post('/locale', ['locale' => 'en', 'redirect' => $t])->headers->get('Location');
            $this->assertSame('localhost', parse_url($loc, PHP_URL_HOST), json_encode($t).' -> '.$loc);
            $resolved = $loc; // browsers resolve relative to the absolute URL: host already fixed
        }
        $a = $this->user('a@example.test');
        $p = $this->mkpost([$a]);
        $loc = $this->actingAs($a)->put("/posts/{$p->id}/meta", ['note' => 'x', 'redirect' => '//evil.test'])->headers->get('Location');
        $this->assertSame('localhost', parse_url($loc, PHP_URL_HOST));
        $loc = $this->actingAs($a)->post('/groups', ['name' => 'q', 'redirect' => 'https://evil.test/'])->headers->get('Location');
        $this->assertSame('localhost', parse_url($loc, PHP_URL_HOST));
    }

    public function test_locale_input_validation(): void
    {
        foreach (['de', 'zh_tw', 'ZH_TW', 'zh-TW', '', ['en']] as $v) {
            $this->post('/locale', ['locale' => $v])->assertStatus(422);
        }
        // cookie value is not reflected unsanitised
        $this->withUnencryptedCookie('locale', '"><script>alert(1)</script>')->get('/login')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        // weird Accept-Language never errors
        foreach (['', '*', 'zh;q=abc', 'en;q=0,zh;q=0', str_repeat('zh-TW,', 5000), 'zh-Hant;q=1.5,en', "zh\x00"] as $h) {
            $this->withHeader('Accept-Language', $h)->get('/login')->assertOk();
        }
    }

    public function test_filter_parameter_abuse(): void
    {
        $a = $this->user('a@example.test');
        $p = $this->mkpost([$a], ['caption' => '100% real_deal']);
        $q = fn (string $s) => $this->actingAs($a)->get('/'.$s);
        foreach (['?q[]=x', '?tag[a][b]=c', '?status[]=done', '?group[]=1', '?group=0', '?group=-1', '?group=1e3', '?group='.str_repeat('9', 30),
            '?q='.str_repeat('x', 100000), '?tag='.str_repeat('t', 5000), '?group=none&tag=zz', '?q=%00', '?q=%27%22--', '?status=gone%00'] as $s) {
            $q($s)->assertOk();
        }
        $this->assertStringContainsString('data-post="'.$p->id.'"', $q('?q=100%25')->getContent());
        $this->assertStringNotContainsString('data-post="'.$p->id.'"', $q('?q=10%25%25')->getContent());
        $this->assertStringContainsString('data-post="'.$p->id.'"', $q('?q=real_deal')->getContent());
        $this->assertStringNotContainsString('data-post="'.$p->id.'"', $q('?q=realXdeal')->getContent());
        $this->assertStringNotContainsString('data-post="'.$p->id.'"', $q('?q=!')->getContent());
        // many tag params capped
        $tags = implode('&', array_map(fn ($i) => 'tag[]=t'.$i, range(1, 500)));
        $q('?'.$tags)->assertOk();
    }

    public function test_deactivated_user_session_and_remember_cookie(): void
    {
        $admin = $this->user('admin@example.test', true);
        $b = $this->user('b@example.test');
        $this->actingAs($b)->get('/')->assertOk();
        $b->forceFill(['is_active' => false])->save();
        $this->actingAs($b->fresh())->get('/')->assertRedirect('/login');
        $this->actingAs($b->fresh())->put('/account/password', [])->assertRedirect('/login');
        $this->actingAs($b->fresh())->post('/groups', ['name' => 'x'])->assertRedirect('/login');
        $this->assertSame(0, Group::count());
        $this->actingAs($b->fresh())->post('/locale', ['locale' => 'en'])->assertRedirect('/');
    }

    public function test_last_admin_guards(): void
    {
        $a = $this->user('a@example.test', true);
        $b = $this->user('b@example.test', true);
        // A demotes B; then A cannot demote self; B (now non-admin) cannot act.
        $this->actingAs($a)->put("/users/{$b->id}", ['name' => 'b', 'email' => 'b@example.test', 'is_admin' => '0', 'is_active' => '1'])->assertRedirect();
        $this->assertFalse((bool) $b->fresh()->is_admin);
        $this->actingAs($a)->put("/users/{$a->id}", ['name' => 'a', 'email' => 'a@example.test', 'is_admin' => '0', 'is_active' => '1']);
        $this->assertTrue((bool) $a->fresh()->is_admin);
        $this->actingAs($a)->delete("/users/{$a->id}");
        $this->assertNotNull($a->fresh());
        // a disabled admin does not count
        $c = $this->user('c@example.test', true);
        $c->forceFill(['is_active' => false])->save();
        $this->actingAs($a)->put("/users/{$a->id}", ['name' => 'a', 'email' => 'a@example.test', 'is_admin' => '1', 'is_active' => '0']);
        $this->assertTrue((bool) $a->fresh()->is_active);
    }

    public function test_account_enumeration_on_users_routes(): void
    {
        $b = $this->user('b@example.test');
        $this->actingAs($b)->get('/users/999999/edit')->assertForbidden();
        $this->actingAs($b)->get('/users/1/edit')->assertForbidden();
        $this->actingAs($b)->delete('/users/999999')->assertForbidden();
    }

    public function test_tag_and_group_limits(): void
    {
        $a = $this->user('a@example.test');
        $p = $this->mkpost([$a]);
        for ($i = 0; $i < 100; $i++) {
            $a->groups()->create(['name' => 'g'.$i, 'sort_order' => $i]);
        }
        $this->actingAs($a)->post('/groups', ['name' => 'over']);
        $this->assertSame(100, Group::where('user_id', $a->id)->count());
        $this->actingAs($a)->put("/posts/{$p->id}/meta", ['group' => 'new', 'new_group' => 'over2']);
        $this->assertSame(100, Group::where('user_id', $a->id)->count());
        // existing name via "new" reuses it
        $this->actingAs($a)->put("/posts/{$p->id}/meta", ['group' => 'new', 'new_group' => 'G5']);
        $this->assertSame(100, Group::where('user_id', $a->id)->count());
        $this->assertSame('g5', $this->entry($a, $p)->group->name);
        // 21 tags rejected
        $this->actingAs($a)->put("/posts/{$p->id}/meta", ['tags' => implode(',', range(1, 21))]);
        $this->assertSame(0, Tag::count());
    }

    public function test_case_variant_group_and_tag_names_do_not_500(): void
    {
        $a = $this->user('a@example.test');
        $p = $this->mkpost([$a]);
        $this->actingAs($a)->post('/groups', ['name' => 'Café'])->assertRedirect();
        $this->actingAs($a)->post('/groups', ['name' => 'Cafe'])->assertRedirect();
        $this->actingAs($a)->post('/groups', ['name' => 'ＣＡＦＥ'])->assertRedirect();
        $this->actingAs($a)->put("/posts/{$p->id}/meta", ['tags' => 'café,cafe,ＣＡＦＥ,İstanbul,istanbul'])->assertRedirect();
        $this->assertGreaterThan(0, Tag::count());
    }

    public function test_xss_in_error_messages_and_old_input(): void
    {
        $a = $this->user('a@example.test');
        $p = $this->mkpost([$a]);
        $x = '<img src=x onerror=alert(7)>';
        $this->actingAs($a)->post('/groups', ['name' => $x]);
        $this->followingRedirects()->actingAs($a)->post('/groups', ['name' => $x, 'redirect' => '/'])->assertDontSee('<img src=x', false);
        $this->followingRedirects()->actingAs($a)->put("/posts/{$p->id}/meta", ['tags' => $x.'#,'.$x, 'note' => str_repeat('a', 1001).$x, 'group' => 'new', 'new_group' => str_repeat('b', 50).$x, 'redirect' => '/'])
            ->assertDontSee('<img src=x', false)->assertSee('&lt;img', false);
        $this->followingRedirects()->actingAs($a)->put("/posts/{$p->id}/meta", ['tags' => $x.'#', 'redirect' => '/posts/'.$p->id])
            ->assertDontSee('<img src=x', false);
        $this->followingRedirects()->actingAs($a)->post('/posts', ['url' => 'https://www.threads.com/'.$x])->assertDontSee('<img src=x', false);
        $this->followingRedirects()->actingAs($a)->put("/groups/999999", ['name' => $x])->assertNotFound();
    }

    public function test_locale_toggle_on_error_page_after_forged_put(): void
    {
        // Error pages are deliberately standalone (no forms, see errors/page.blade.php): there is no
        // locale form whose "redirect" a forged request could point elsewhere. If one is ever
        // added, it must send the visitor back to this very path.
        $a = $this->user('a@example.test');
        foreach ([$this->actingAs($a)->get('/posts/424242'), $this->actingAs($a)->put('/posts/424242/meta', ['redirect' => '//evil.test'])] as $r) {
            $html = $r->assertNotFound()->getContent();
            $this->assertStringNotContainsString('evil.test', $html);
            if (preg_match_all('/name="redirect" value="([^"]*)"/', $html, $m)) {
                foreach ($m[1] as $value) {
                    $this->assertStringStartsWith('/posts/424242', $value);
                }
            } else {
                $this->assertStringNotContainsString('name="redirect"', $html);
            }
        }
    }

    public function test_first_submitter_username_and_nonascii_search(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $this->actingAs($a)->post('/posts', ['url' => 'https://www.threads.com/@fake.brand/post/AAAAAAA']);
        $this->actingAs($b)->post('/posts', ['url' => 'https://www.threads.com/@real_owner/post/AAAAAAA']);
        $html = $this->actingAs($b)->get('/')->getContent();
        // RA-F16 fixed: the first submitter's @name is shown only with the "unverified" label, and
        // "Open post" uses the canonical /t/{shortcode} link, never the unverified @name.
        $this->assertStringContainsString('@fake.brand', $html);
        $this->assertStringContainsString('>'.__('posts.unverified_account').'</span>', $html);
        $this->assertStringContainsString('href="https://www.threads.com/t/AAAAAAA"', $html);
        $this->assertStringNotContainsString('threads.com/@fake.brand', $html);
        $this->assertStringNotContainsString('real_owner', $html);
        // RA-F3 fixed: non-ASCII case folding on SQLite.
        $p = $this->mkpost([$a], ['caption' => 'École Ünïcode ΑΒΓ']);
        foreach (['école', 'ÉCOLE', 'École', 'ünïcode', 'αβγ'] as $q) {
            $hit = str_contains($this->actingAs($a)->get('/?q='.urlencode($q))->getContent(), 'data-post="'.$p->id.'"');
            $this->assertTrue($hit, "search $q");
        }
        config(['threads.track_hours' => 48]);
        $p2 = $this->mkpost([$a], ['status' => 'done', 'added_after_window' => true]);
        $html = $this->actingAs($a)->get('/posts/'.$p2->id)->getContent();
        $this->assertMatchesRegularExpression('/48-hour|48 hours/', $html);
        $this->assertDoesNotMatchRegularExpression('/72-hour|72 hours/', $html);
    }
}
