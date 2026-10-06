<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Tag;
use App\Models\TrackedPost;
use App\Models\TrackedPostUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Contracts G1–G4: per-user groups, the grouped list, the group manager and the edit area. */
class GroupsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $email = 'a@example.test'): User
    {
        return User::firstOrCreate(['email' => $email], ['name' => 'u', 'password' => 'correct-horse-battery']);
    }

    private function makePost(array $attrs = [], array $owners = ['a@example.test']): TrackedPost
    {
        $this->seq++;
        $code = 'DFakeGrp'.str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT);
        $post = TrackedPost::create(array_merge([
            'url' => 'https://www.threads.com/t/'.$code, 'shortcode' => $code, 'username' => 'user_'.$this->seq,
            'published_at' => CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'), 'published_at_source' => 'page',
            'track_until' => CarbonImmutable::parse('2026-10-08 17:00:00', 'UTC'), 'status' => 'tracking',
        ], $attrs));
        foreach ($owners as $email) {
            $this->user($email)->trackedPosts()->attach($post->id);
        }

        return $post;
    }

    private function group(User $u, string $name, int $order = 0): Group
    {
        return $u->groups()->create(['name' => $name, 'sort_order' => $order]);
    }

    private function entry(User $u, TrackedPost $p): TrackedPostUser
    {
        return TrackedPostUser::where('user_id', $u->id)->where('tracked_post_id', $p->id)->sole();
    }

    private function put_in(User $u, TrackedPost $p, ?Group $g): void
    {
        $e = $this->entry($u, $p);
        $e->group_id = $g?->id;
        $e->save();
    }

    /**
     * The list page's group sections, in order: key, name, count text, open flag and post ids.
     *
     * @return list<array{key: string, name: string, count: string, open: bool, posts: list<int>}>
     */
    private function sections(string $query = '', ?User $as = null): array
    {
        $html = $this->actingAs($as ?? $this->user())->get('/'.$query)->assertOk()->getContent();
        $parts = preg_split('/<details class="group-section" /', $html);
        array_shift($parts);
        $out = [];
        foreach ($parts as $part) {
            $part = explode('</details>'."\n".'        <details class="group-section"', $part)[0];
            preg_match('/^data-group-key="([^"]+)"( open)?>/', $part, $head);
            preg_match('#<span class="group-name">(.*?)</span>#s', $part, $name);
            preg_match('#<span class="group-count">(.*?)</span>#s', $part, $count);
            preg_match_all('/data-post="(\d+)"/', $part, $ids);
            $out[] = [
                'key' => $head[1], 'name' => html_entity_decode($name[1], ENT_QUOTES), 'count' => trim($count[1]),
                'open' => ($head[2] ?? '') !== '', 'posts' => array_map('intval', $ids[1]),
            ];
        }

        return $out;
    }

    // ---- G1 model and G3 manager ----

    public function test_create_rename_delete_and_names_are_unique_per_user_case_insensitively(): void
    {
        $a = $this->user();
        $this->actingAs($a)->post('/groups', ['name' => '  Product   Launch ', 'redirect' => '/?q=x'])
            ->assertRedirect('/?q=x')->assertSessionHas('status', 'Group "Product Launch" added.');
        $g = $a->groups()->sole();
        $this->assertSame('Product Launch', $g->name);

        // Duplicate (any casing) is refused, with the error in the "groups" bag and the manager reopened.
        $this->post('/groups', ['name' => 'product LAUNCH', 'redirect' => '/'])
            ->assertRedirect('/')->assertSessionHasErrors(['name'], null, 'groups')->assertSessionHas('group_form', 'new');
        $this->assertSame(1, Group::count());

        foreach (['', '   ', str_repeat('x', 41)] as $bad) {
            $this->post('/groups', ['name' => $bad])->assertSessionHasErrors(['name'], null, 'groups');
        }
        $this->post('/groups', ['name' => ['array']])->assertSessionHasErrors(['name'], null, 'groups');
        $this->post('/groups', ['name' => str_repeat('長', 40)])->assertSessionHasNoErrors();
        $this->assertSame(2, Group::count());

        // Another user may use the same name.
        $b = $this->user('b@example.test');
        $this->actingAs($b)->post('/groups', ['name' => 'Product Launch'])->assertSessionHasNoErrors();
        $this->assertSame(1, $b->groups()->count());

        // Rename: to its own name in another casing is fine; to another of the user's names is not.
        $this->actingAs($a)->put("/groups/{$g->id}", ['name' => 'PRODUCT launch'])->assertSessionHasNoErrors();
        $this->assertSame('PRODUCT launch', $g->fresh()->name);
        $this->put("/groups/{$g->id}", ['name' => str_repeat('長', 40)])
            ->assertSessionHasErrors(['name'], null, 'groups')->assertSessionHas('group_form', (string) $g->id);
        $this->put("/groups/{$g->id}", ['name' => 'Campaign'])->assertSessionHas('status', 'Group renamed to "Campaign".');
        $this->assertSame('Campaign', $g->fresh()->name);

        $this->delete("/groups/{$g->id}", ['redirect' => '/?status=done'])->assertRedirect('/?status=done');
        $this->assertNull($g->fresh());
    }

    public function test_redirect_only_goes_to_same_site_paths(): void
    {
        $this->actingAs($this->user())->post('/groups', ['name' => 'X', 'redirect' => 'https://evil.example/'])->assertRedirect('/');
        $this->post('/groups', ['name' => 'Y', 'redirect' => '//evil.example/'])->assertRedirect('/');
        $this->post('/groups', ['name' => 'Z'])->assertRedirect('/');
    }

    public function test_move_up_and_down_reorders_and_renumbers(): void
    {
        $a = $this->user();
        $this->actingAs($a);
        foreach (['One', 'Two', 'Three'] as $name) {
            $this->post('/groups', ['name' => $name])->assertSessionHasNoErrors();
        }
        $order = fn () => $a->groups()->pluck('name')->all();
        $this->assertSame(['One', 'Two', 'Three'], $order());
        [$one, $two, $three] = $a->groups()->get()->all();

        $this->post("/groups/{$three->id}/move", ['direction' => 'up'])->assertRedirect('/')->assertSessionHas('status', 'Group order updated.');
        $this->assertSame(['One', 'Three', 'Two'], $order());
        $this->post("/groups/{$one->id}/move", ['direction' => 'down']);
        $this->assertSame(['Three', 'One', 'Two'], $order());
        // At the edges nothing moves.
        $this->post("/groups/{$three->id}/move", ['direction' => 'up']);
        $this->post("/groups/{$two->id}/move", ['direction' => 'down']);
        $this->assertSame(['Three', 'One', 'Two'], $order());
        $this->assertSame([1, 2, 3], $a->groups()->pluck('sort_order')->all());

        // Ties (e.g. equal sort_order) are resolved by id, then renumbered.
        Group::query()->update(['sort_order' => 0]);
        $this->post("/groups/{$one->id}/move", ['direction' => 'down']);
        $this->assertSame(['Two', 'One', 'Three'], $order());
        $this->assertSame([1, 2, 3], $a->groups()->pluck('sort_order')->all());

        foreach (['sideways', '', null] as $bad) {
            $this->post("/groups/{$one->id}/move", ['direction' => $bad])->assertStatus(422);
        }

        // The list shows the sections in this order.
        $this->assertSame($order(), array_column($this->sections(), 'name'));
    }

    public function test_other_users_groups_are_404_for_every_action(): void
    {
        $a = $this->user();
        $b = $this->user('b@example.test');
        $theirs = $this->group($b, 'Private', 1);
        $post = $this->makePost([], ['b@example.test']);
        $this->put_in($b, $post, $theirs);

        $this->actingAs($a);
        $this->put("/groups/{$theirs->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->post("/groups/{$theirs->id}/move", ['direction' => 'up'])->assertNotFound();
        $this->delete("/groups/{$theirs->id}")->assertNotFound();
        $this->put('/groups/999999', ['name' => 'x'])->assertNotFound();

        $this->assertSame('Private', $theirs->fresh()->name);
        $this->assertSame($theirs->id, $this->entry($b, $post)->group_id);
        // And the list shows only the user's own groups.
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Private', $html);
    }

    public function test_deleting_a_group_moves_its_posts_to_ungrouped_and_keeps_their_data(): void
    {
        $a = $this->user();
        $g = $this->group($a, 'Campaign', 1);
        $p1 = $this->makePost();
        $p2 = $this->makePost();
        $this->actingAs($a)->put("/posts/{$p1->id}/meta", ['group' => (string) $g->id, 'tags' => 'keep, me', 'note' => 'my note'])->assertSessionHasNoErrors();
        $this->put_in($a, $p2, $g);
        $p1->snapshots()->create(['captured_at' => now(), 'source' => 'public_page', 'views' => 5]);

        // The confirmation names the group and the number of posts in it.
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('data-confirm="Delete the group &quot;Campaign&quot;?'."\n\n".'Its posts (2) go back to Ungrouped', $html);

        $this->delete("/groups/{$g->id}")->assertRedirect('/')
            ->assertSessionHas('status', 'Group "Campaign" deleted; its posts are back in Ungrouped.');

        $this->assertSame(0, Group::count());
        $e = $this->entry($a, $p1);
        $this->assertNull($e->group_id);
        $this->assertNull($this->entry($a, $p2)->group_id);
        $this->assertSame('my note', $e->note);
        $this->assertEqualsCanonicalizing(['keep', 'me'], $e->tags()->pluck('name')->all());
        $this->assertSame(2, TrackedPost::count());
        $this->assertSame(1, DB::table('post_snapshots')->count());

        $this->assertSame([['key' => 'none', 'posts' => [$p2->id, $p1->id]]],
            array_map(fn ($s) => ['key' => $s['key'], 'posts' => $s['posts']], $this->sections()));
    }

    public function test_deleting_a_user_deletes_their_groups(): void
    {
        $a = $this->user();
        $this->group($a, 'G', 1);
        $a->delete();
        $this->assertSame(0, Group::count());
    }

    // ---- G2 grouped list ----

    public function test_list_is_in_group_sections_with_counts_and_ungrouped_last(): void
    {
        $a = $this->user();
        $second = $this->group($a, 'Second', 2);
        $first = $this->group($a, 'First', 1);
        $empty = $this->group($a, 'Empty', 3);
        $p1 = $this->makePost();
        $p2 = $this->makePost();
        $p3 = $this->makePost();
        $p4 = $this->makePost();
        $this->put_in($a, $p1, $first);
        $this->put_in($a, $p2, $first);
        $this->put_in($a, $p3, $second);

        $sections = $this->sections();
        $this->assertSame(['g'.$first->id, 'g'.$second->id, 'g'.$empty->id, 'none'], array_column($sections, 'key'));
        $this->assertSame(['First', 'Second', 'Empty', 'Ungrouped'], array_column($sections, 'name'));
        $this->assertSame(['2 posts', '1 post', '0 posts', '1 post'], array_column($sections, 'count'));
        $this->assertSame([[$p2->id, $p1->id], [$p3->id], [], [$p4->id]], array_column($sections, 'posts'));
        // Closed by default; the empty group says so.
        $this->assertSame([false, false, false, false], array_column($sections, 'open'));
        $this->get('/')->assertSee('No posts in this group yet.');

        // No Ungrouped section when every post has a group.
        $this->put_in($a, $p4, $empty);
        $this->assertSame(['First', 'Second', 'Empty'], array_column($this->sections(), 'name'));

        // Traditional Chinese.
        $this->put_in($a, $p4, null);
        $zh = $this->withHeader('Accept-Language', 'zh-TW')->get('/')->getContent();
        $this->assertStringContainsString('<span class="group-name">未分類</span>', $zh);
        $this->assertStringContainsString('<span class="group-count">2 篇</span>', $zh);
    }

    public function test_groups_without_posts_show_even_when_the_list_is_empty(): void
    {
        $a = $this->user();
        $this->group($a, 'Ideas', 1);
        $sections = $this->sections();
        $this->assertSame(['Ideas'], array_column($sections, 'name'));
        $this->get('/')->assertSee('No posts tracked yet.');
    }

    public function test_other_users_grouping_of_the_same_post_is_separate(): void
    {
        $a = $this->user();
        $b = $this->user('b@example.test');
        $shared = $this->makePost([], ['a@example.test', 'b@example.test']);
        $gb = $this->group($b, 'Theirs', 1);
        $this->put_in($b, $shared, $gb);

        $this->assertSame([['key' => 'none', 'posts' => [$shared->id]]],
            array_map(fn ($s) => ['key' => $s['key'], 'posts' => $s['posts']], $this->sections('', $a)));
        $this->assertSame(['Theirs'], array_column($this->sections('', $b), 'name'));
    }

    public function test_filters_show_only_matching_sections_open_and_group_parameter_combines(): void
    {
        $a = $this->user();
        $g1 = $this->group($a, 'Alpha', 1);
        $g2 = $this->group($a, 'Beta', 2);
        $this->group($a, 'Empty', 3);
        $p1 = $this->makePost(['caption' => 'coffee launch']);
        $p2 = $this->makePost(['caption' => 'tea', 'status' => 'done']);
        $p3 = $this->makePost(['caption' => 'coffee again', 'status' => 'done']);
        $p4 = $this->makePost(['caption' => 'coffee loose']);
        $this->put_in($a, $p1, $g1);
        $this->put_in($a, $p2, $g2);
        $this->put_in($a, $p3, $g2);
        $this->actingAs($a)->put("/posts/{$p3->id}/meta", ['tags' => 'promo'])->assertSessionHasNoErrors();

        $s = $this->sections('?q=coffee');
        $this->assertSame(['Alpha', 'Beta', 'Ungrouped'], array_column($s, 'name'));
        $this->assertSame([true, true, true], array_column($s, 'open'));
        $this->assertSame(['1 post', '1 post', '1 post'], array_column($s, 'count'));
        $this->assertSame([[$p1->id], [$p3->id], [$p4->id]], array_column($s, 'posts'));

        $this->assertSame([[$p3->id, $p2->id]], array_column($this->sections('?group='.$g2->id), 'posts'));
        $this->assertSame([[$p4->id]], array_column($this->sections('?group=none'), 'posts'));
        $this->assertSame(['Ungrouped'], array_column($this->sections('?group=none'), 'name'));
        $this->assertSame([[$p3->id]], array_column($this->sections('?group='.$g2->id.'&q=coffee&status=done&tag[]=promo'), 'posts'));
        $this->assertSame([], $this->sections('?group='.$g1->id.'&status=done'));

        // Another user's group id, or one that does not exist: an empty list, like an unknown tag.
        $b = $this->user('b@example.test');
        $theirs = $this->group($b, 'Theirs', 1);
        $this->assertSame([], $this->sections('?group='.$theirs->id, $a));
        $this->actingAs($a)->get('/?group=999999')->assertOk()->assertSee('No posts match.');
        // Malformed values are ignored (the unfiltered list, sections closed).
        foreach (['?group=abc', '?group[]=1', '?group=0', '?group=-1', '?group='] as $q) {
            $s = $this->sections($q, $a);
            $this->assertSame(['Alpha', 'Beta', 'Empty', 'Ungrouped'], array_column($s, 'name'), $q);
            $this->assertSame([false, false, false, false], array_column($s, 'open'), $q);
        }

        // The filter bar has the group menu with the current choice, and a chip.
        $html = $this->actingAs($a)->get('/?group='.$g2->id.'&tag[]=promo')->getContent();
        $this->assertMatchesRegularExpression('#<option value="'.$g2->id.'" selected>Beta</option>#', $html);
        $this->assertStringContainsString('Group: Beta', $html);
        // Removing the tag chip keeps the group.
        $this->assertStringContainsString('href="'.e(route('posts.index', ['group' => $g2->id])).'"', $html);
        $this->assertStringContainsString('Group: Ungrouped', $this->get('/?group=none')->getContent());
    }

    public function test_list_script_remembers_open_sections_and_is_not_required(): void
    {
        $this->group($this->user(), 'G', 1);
        $html = $this->actingAs($this->user())->get('/')->getContent();
        $this->assertStringContainsString("var KEY = 'groups-open';", $html);
        $this->assertStringContainsString('try { localStorage.setItem(KEY', $html);
        $this->assertStringContainsString('localStorage.getItem(KEY)', $html);
        // Sections are plain <details>, so they open and close without JavaScript.
        $this->assertMatchesRegularExpression('#<details class="group-section" data-group-key="g\d+">\s*<summary>#', $html);
        $this->assertStringContainsString('data-group-list aria-label', $html);
        $this->assertStringContainsString('data-group-list data-filtered aria-label', $this->get('/?q=x')->getContent());
    }

    // ---- G4 edit area and PUT /meta ----

    public function test_meta_updates_group_tags_and_note_at_once_and_keeps_the_list_url(): void
    {
        $a = $this->user();
        $g = $this->group($a, 'Campaign', 1);
        $post = $this->makePost();

        $back = '/?q=coffee&status=tracking&group=none#post-'.$post->id;
        $this->actingAs($a)->put("/posts/{$post->id}/meta", [
            'group' => (string) $g->id, 'tags' => 'Alpha, #beta', 'note' => "line 1\r\nline 2 ", 'redirect' => $back,
        ])->assertRedirect($back)->assertSessionHas('status', 'Updated.');

        $e = $this->entry($a, $post);
        $this->assertSame($g->id, (int) $e->group_id);
        $this->assertEqualsCanonicalizing(['Alpha', 'beta'], $e->tags()->pluck('name')->all());
        $this->assertSame("line 1\nline 2", $e->note);

        // Back to Ungrouped, tags and note cleared.
        $this->put("/posts/{$post->id}/meta", ['group' => 'none', 'tags' => '', 'note' => ''])->assertRedirect("/posts/{$post->id}");
        $e = $this->entry($a, $post);
        $this->assertNull($e->group_id);
        $this->assertSame([], $e->tags()->pluck('name')->all());
        $this->assertNull($e->note);
        $this->assertSame(0, Tag::count());

        // A field that is not sent stays as it is.
        $this->put("/posts/{$post->id}/meta", ['note' => 'only the note']);
        $this->put("/posts/{$post->id}/meta", ['group' => (string) $g->id]);
        $e = $this->entry($a, $post);
        $this->assertSame('only the note', $e->note);
        $this->assertSame($g->id, (int) $e->group_id);
    }

    public function test_meta_new_group_option_creates_the_group_or_reuses_an_existing_one(): void
    {
        $a = $this->user();
        $existing = $this->group($a, 'Existing', 5);
        $p1 = $this->makePost();
        $p2 = $this->makePost();

        $this->actingAs($a)->put("/posts/{$p1->id}/meta", ['group' => 'new', 'new_group' => '  Fresh  one ', 'tags' => '', 'note' => ''])
            ->assertSessionHasNoErrors();
        $fresh = Group::where('name', 'Fresh one')->sole();
        $this->assertSame($a->id, $fresh->user_id);
        $this->assertSame(6, $fresh->sort_order); // added at the end
        $this->assertSame($fresh->id, (int) $this->entry($a, $p1)->group_id);

        $this->put("/posts/{$p2->id}/meta", ['group' => 'new', 'new_group' => 'EXISTING'])->assertSessionHasNoErrors();
        $this->assertSame($existing->id, (int) $this->entry($a, $p2)->group_id);
        $this->assertSame(2, Group::count());

        // new_group is ignored unless "new" is selected.
        $this->put("/posts/{$p2->id}/meta", ['group' => 'none', 'new_group' => 'Ignored'])->assertSessionHasNoErrors();
        $this->assertSame(2, Group::count());

        // An empty or too long name is an error; nothing at all is saved.
        $this->put("/posts/{$p2->id}/meta", ['group' => 'new', 'new_group' => ' ', 'tags' => 'x', 'note' => 'y', 'redirect' => '/?q=a'])
            ->assertRedirect('/?q=a')->assertSessionHasErrors(['new_group'], null, 'meta')->assertSessionHas('meta_post', $p2->id)
            ->assertSessionHasInput('tags', 'x');
        $this->put("/posts/{$p2->id}/meta", ['group' => 'new', 'new_group' => str_repeat('x', 41)])->assertSessionHasErrors(['new_group'], null, 'meta');
        $e = $this->entry($a, $p2);
        $this->assertNull($e->group_id);
        $this->assertSame([], $e->tags()->pluck('name')->all());
        $this->assertNull($e->note);
        $this->assertSame(2, Group::count());
    }

    public function test_meta_validation_errors_change_nothing_and_reopen_the_card(): void
    {
        $a = $this->user();
        $g = $this->group($a, 'G', 1);
        $post = $this->makePost();
        $other = $this->makePost();
        $this->actingAs($a)->put("/posts/{$post->id}/meta", ['group' => (string) $g->id, 'tags' => 'old', 'note' => 'old note']);

        $cases = [
            ['tags' => 'a,b#c', 'note' => 'new'],
            ['tags' => implode(',', range(1, 21)), 'note' => 'new'],
            ['tags' => 'new', 'note' => str_repeat('x', 1001)],
            ['tags' => ['array'], 'note' => 'new'],
            ['tags' => 'new', 'note' => ['array']],
        ];
        foreach ($cases as $data) {
            $this->put("/posts/{$post->id}/meta", $data + ['group' => 'none', 'redirect' => '/#post-'.$post->id])
                ->assertRedirect('/#post-'.$post->id)->assertSessionHas('meta_post', $post->id);
            $e = $this->entry($a, $post);
            $this->assertSame($g->id, (int) $e->group_id, json_encode($data));
            $this->assertSame(['old'], $e->tags()->pluck('name')->all());
            $this->assertSame('old note', $e->note);
        }

        // The list renders the card's group section and edit area open, with the old input and the error.
        $html = $this->followingRedirects()->put("/posts/{$post->id}/meta", ['group' => 'none', 'tags' => 'a,b#c', 'note' => 'typed', 'redirect' => '/'])->getContent();
        $this->assertMatchesRegularExpression('#<details class="group-section" data-group-key="g'.$g->id.'" open>#', $html);
        $this->assertMatchesRegularExpression('#<article class="card post-card" id="post-'.$post->id.'".*?<details class="post-edit" open>#s', $html);
        $this->assertStringContainsString('value="a,b#c"', $html);
        $this->assertStringContainsString('>typed</textarea>', $html);
        $this->assertStringContainsString('cannot contain a comma or #', $html);
        // Only that card: the other card's edit area stays closed.
        $this->assertMatchesRegularExpression('#id="post-'.$other->id.'".*?<details class="post-edit">#s', $html);
    }

    public function test_meta_rejects_another_users_group_and_post(): void
    {
        $a = $this->user();
        $b = $this->user('b@example.test');
        $theirs = $this->group($b, 'Theirs', 1);
        $mine = $this->makePost();
        $theirPost = $this->makePost([], ['b@example.test']);

        foreach ([(string) $theirs->id, '999999', 'abc', '1.5'] as $bad) {
            $this->actingAs($a)->put("/posts/{$mine->id}/meta", ['group' => $bad, 'tags' => 'x', 'note' => 'n'])->assertStatus(422);
        }
        $this->put("/posts/{$mine->id}/meta", ['group' => ['1'], 'tags' => 'x'])->assertStatus(422);
        $e = $this->entry($a, $mine);
        $this->assertNull($e->group_id);
        $this->assertSame([], $e->tags()->pluck('name')->all());
        $this->assertNull($e->note);
        $this->assertSame(0, $theirs->entries()->count());

        // Another user's post: 404 (even with the user's own group).
        $ownGroup = $this->group($a, 'Mine', 1);
        $this->put("/posts/{$theirPost->id}/meta", ['group' => (string) $ownGroup->id, 'note' => 'x'])->assertNotFound();
        $this->assertNull($this->entry($b, $theirPost)->note);
    }

    public function test_meta_is_one_transaction(): void
    {
        $a = $this->user();
        $old = $this->group($a, 'Old', 1);
        $post = $this->makePost();
        $this->actingAs($a)->put("/posts/{$post->id}/meta", ['group' => (string) $old->id, 'tags' => 'keep', 'note' => 'before']);

        // Fail on the last write (the entry itself), after the group and tags were written.
        TrackedPostUser::saving(function () {
            throw new RuntimeException('simulated failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->put("/posts/{$post->id}/meta", ['group' => 'new', 'new_group' => 'Brand new', 'tags' => 'changed, more', 'note' => 'after']);
            $this->fail('expected the simulated failure');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }
        TrackedPostUser::flushEventListeners();

        $this->assertSame(['Old'], $a->groups()->pluck('name')->all());
        $e = $this->entry($a, $post);
        $this->assertSame($old->id, (int) $e->group_id);
        $this->assertSame(['keep'], $e->tags()->pluck('name')->all());
        $this->assertSame('before', $e->note);
        $this->assertEqualsCanonicalizing(['keep'], Tag::pluck('name')->all());
    }

    public function test_every_card_has_an_edit_area_with_the_current_values(): void
    {
        $a = $this->user();
        $g = $this->group($a, 'Campaign', 1);
        $other = $this->group($a, 'Other', 2);
        $post = $this->makePost();
        $this->actingAs($a)->put("/posts/{$post->id}/meta", ['group' => (string) $g->id, 'tags' => 'one, two', 'note' => 'a note']);

        $html = $this->get('/?q=user&status=tracking')->getContent();
        $this->assertStringContainsString('<details class="post-edit">', $html);
        $this->assertStringContainsString('<summary>Edit</summary>', $html);
        $this->assertStringContainsString('action="'.route('posts.meta.update', $post).'"', $html);
        $this->assertStringContainsString('name="redirect" value="/?q=user&amp;status=tracking#post-'.$post->id.'"', $html);
        $this->assertMatchesRegularExpression('#<option value="'.$g->id.'" selected>Campaign</option>#', $html);
        $this->assertStringContainsString('<option value="'.$other->id.'" >Other</option>', $html);
        $this->assertStringContainsString('<option value="new" >New group…</option>', $html);
        $this->assertStringContainsString('<option value="none" >Ungrouped</option>', $html);
        $this->assertStringContainsString('value="one, two"', $html);
        $this->assertStringContainsString('>a note</textarea>', $html);
        $this->assertStringContainsString('id="p'.$post->id.'-new-group"', $html);
    }

    public function test_post_page_has_the_edit_area_at_the_top(): void
    {
        $a = $this->user();
        $g = $this->group($a, 'Campaign', 1);
        $post = $this->makePost();
        $this->actingAs($a)->put("/posts/{$post->id}/meta", ['group' => (string) $g->id, 'tags' => 'one', 'note' => 'n']);
        $post->snapshots()->create(['captured_at' => now(), 'hours_since_publish' => 1, 'source' => 'public_page', 'views' => 5]);

        $html = $this->get("/posts/{$post->id}")->assertOk()->getContent();
        $edit = strpos($html, 'Group, tags and note');
        $this->assertNotFalse($edit);
        $this->assertLessThan(strpos($html, 'Views over time'), $edit);
        $this->assertLessThan(strpos($html, 'Fetch log'), $edit);
        $this->assertStringContainsString('action="'.route('posts.meta.update', $post).'"', $html);
        $this->assertStringContainsString('name="redirect" value="/posts/'.$post->id.'"', $html);
        $this->assertMatchesRegularExpression('#<option value="'.$g->id.'" selected>Campaign</option>#', $html);
        $this->assertStringContainsString('Group: Campaign', $html);
        // The old separate forms are gone from the page; their routes still work.
        $this->assertStringNotContainsString('action="'.route('posts.tags.update', $post).'"', $html);
        $this->put("/posts/{$post->id}/tags", ['tags' => 'legacy'])->assertRedirect("/posts/{$post->id}");
        $this->put("/posts/{$post->id}/note", ['note' => 'legacy note'])->assertRedirect("/posts/{$post->id}");
        $e = $this->entry($a, $post);
        $this->assertSame(['legacy'], $e->tags()->pluck('name')->all());
        $this->assertSame('legacy note', $e->note);
        $this->assertSame($g->id, (int) $e->group_id);

        // Errors from the old /tags route still show on the page.
        $this->followingRedirects()->from("/posts/{$post->id}")->put("/posts/{$post->id}/tags", ['tags' => 'a,b#c'])
            ->assertSee('cannot contain a comma or #');
    }

    public function test_add_form_still_only_asks_for_the_url(): void
    {
        $html = $this->actingAs($this->user())->get('/')->getContent();
        preg_match('#<form method="POST" action="'.preg_quote(route('posts.store'), '#').'">(.*?)</form>#s', $html, $m);
        $this->assertNotEmpty($m);
        preg_match_all('/name="([^"]+)"/', $m[1], $names);
        $this->assertSame(['_token', 'url'], $names[1]);
    }
}
