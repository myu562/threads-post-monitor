<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TrackedPost;
use App\Models\TrackedPostUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Contract K3: per-user tags and notes. */
class TagsAndNotesTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private function user(string $email = 'a@example.test'): User
    {
        return User::firstOrCreate(['email' => $email], ['name' => 'u', 'password' => 'correct-horse-battery']);
    }

    private function makePost(string $code = 'DFakeP0st01', array $owners = ['a@example.test']): TrackedPost
    {
        $post = TrackedPost::create([
            'url' => 'https://www.threads.com/@example_user/post/'.$code, 'shortcode' => $code, 'username' => 'example_user',
            'published_at' => CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'), 'published_at_source' => 'page',
            'track_until' => CarbonImmutable::parse('2026-10-08 17:00:00', 'UTC'), 'status' => 'tracking',
        ]);
        foreach ($owners as $email) {
            $this->user($email)->trackedPosts()->attach($post->id);
        }

        return $post;
    }

    private function entry(User $u, TrackedPost $p): TrackedPostUser
    {
        return TrackedPostUser::where('user_id', $u->id)->where('tracked_post_id', $p->id)->sole();
    }

    private function tagNames(User $u, TrackedPost $p): array
    {
        return $this->entry($u, $p)->tags()->pluck('name')->all();
    }

    public function test_tags_are_normalised_deduplicated_and_replace_the_whole_set(): void
    {
        $a = $this->user();
        $post = $this->makePost();

        $this->actingAs($a)->put("/posts/{$post->id}/tags", ['tags' => "  Product   Launch ,#活動, product launch，, 合作 "])
            ->assertRedirect("/posts/{$post->id}")->assertSessionHas('status', '標籤已更新。');
        $this->assertEqualsCanonicalizing(['Product Launch', '活動', '合作'], $this->tagNames($a, $post));

        // Whole set is replaced; existing tag matched case-insensitively keeps its first spelling.
        $this->put("/posts/{$post->id}/tags", ['tags' => 'PRODUCT LAUNCH, 新的'])->assertRedirect();
        $this->assertEqualsCanonicalizing(['Product Launch', '新的'], $this->tagNames($a, $post));
        // Tags no post uses any more are deleted.
        $this->assertEqualsCanonicalizing(['Product Launch', '新的'], $a->tags()->pluck('name')->all());

        // Empty input clears.
        $this->put("/posts/{$post->id}/tags", ['tags' => ' , '])->assertSessionHas('status', '已清除標籤。');
        $this->assertSame([], $this->tagNames($a, $post));
        $this->assertSame(0, Tag::count());
    }

    public function test_tag_validation_rejects_long_names_inner_hash_and_more_than_twenty(): void
    {
        $a = $this->user();
        $post = $this->makePost();
        $this->actingAs($a)->put("/posts/{$post->id}/tags", ['tags' => 'keep'])->assertRedirect();

        $cases = [
            str_repeat('長', 31) => '最多 30 個字',
            'C#語言' => '不能包含逗號或 #',
            implode(',', array_map(fn ($i) => "t{$i}", range(1, 21))) => '最多 20 個標籤',
        ];
        foreach ($cases as $input => $msg) {
            $this->from("/posts/{$post->id}")->put("/posts/{$post->id}/tags", ['tags' => $input])
                ->assertRedirect("/posts/{$post->id}")
                ->assertSessionHasErrors(['tags'], null, 'tags');
            $this->assertStringContainsString($msg, session('errors')->getBag('tags')->first('tags'));
            $this->assertSame(['keep'], $this->tagNames($a, $post), 'a rejected input changes nothing');
        }

        // Exactly 30 characters and exactly 20 tags are fine.
        $this->put("/posts/{$post->id}/tags", ['tags' => str_repeat('長', 30)])->assertSessionHasNoErrors();
        $this->put("/posts/{$post->id}/tags", ['tags' => implode(',', array_map(fn ($i) => "t{$i}", range(1, 20)))])->assertSessionHasNoErrors();
        $this->assertCount(20, $this->tagNames($a, $post));

        // Non-string input
        $this->put("/posts/{$post->id}/tags", ['tags' => ['x', 'y']])->assertSessionHasErrors(['tags'], null, 'tags');
    }

    public function test_tags_are_per_user_even_on_the_same_post(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $post = $this->makePost('DFakeP0st01', ['a@example.test', 'b@example.test']);

        $this->actingAs($a)->put("/posts/{$post->id}/tags", ['tags' => '機密, 共用名稱'])->assertRedirect();
        $this->actingAs($b)->put("/posts/{$post->id}/tags", ['tags' => '共用名稱'])->assertRedirect();

        $this->assertSame(['共用名稱'], $this->tagNames($b, $post));
        $this->assertSame(2, Tag::where('name', '共用名稱')->count()); // one per user, never shared
        $this->assertSame([], $b->tags()->where('name', '機密')->pluck('id')->all());

        $html = $this->actingAs($b)->get("/posts/{$post->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('機密', $html);
        $this->actingAs($b)->get('/')->assertOk()->assertDontSee('機密');
        $this->actingAs($b)->get('/?tag[]=機密')->assertOk()->assertSee('沒有符合條件的貼文');
    }

    public function test_cannot_update_tags_or_note_on_someone_elses_post(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $bPost = $this->makePost('DFakeP0st02', ['b@example.test']);
        $this->actingAs($b)->put("/posts/{$bPost->id}/tags", ['tags' => 'b-tag'])->assertRedirect();
        $this->actingAs($b)->put("/posts/{$bPost->id}/note", ['note' => 'b note'])->assertRedirect();

        $a->forceFill(['is_admin' => true])->save(); // admins get no exception either
        $this->actingAs($a)->put("/posts/{$bPost->id}/tags", ['tags' => 'hijack'])->assertNotFound();
        $this->actingAs($a)->put("/posts/{$bPost->id}/note", ['note' => 'hijack'])->assertNotFound();
        $this->actingAs($a)->put('/posts/999999/tags', ['tags' => 'x'])->assertNotFound();

        $this->assertSame(['b-tag'], $this->tagNames($b, $bPost));
        $this->assertSame('b note', $this->entry($b, $bPost)->note);
        $this->assertSame(0, $a->tags()->count());
    }

    public function test_cannot_attach_another_users_tag_id_to_own_post(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $aPost = $this->makePost('DFakeP0st01', ['a@example.test']);
        $bPost = $this->makePost('DFakeP0st02', ['b@example.test']);
        $this->actingAs($b)->put("/posts/{$bPost->id}/tags", ['tags' => 'b-only'])->assertRedirect();
        $bTag = $b->tags()->sole();

        // Tag ids in any shape are ignored: only names are read, and only resolved against own tags.
        $this->actingAs($a)->put("/posts/{$aPost->id}/tags", [
            'tags' => (string) $bTag->id, 'tag_ids' => [$bTag->id], 'tag_id' => $bTag->id,
        ])->assertRedirect();

        $aEntry = $this->entry($a, $aPost);
        $this->assertFalse(DB::table('tag_tracked_post_user')->where('tracked_post_user_id', $aEntry->id)->where('tag_id', $bTag->id)->exists());
        $this->assertSame([(string) $bTag->id], $this->tagNames($a, $aPost)); // a new tag of A's own, named like the id
        $this->assertTrue($a->tags()->sole()->isNot($bTag));
        $this->assertSame(['b-only'], $this->tagNames($b, $bPost));
    }

    public function test_note_is_saved_trimmed_limited_and_private(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $post = $this->makePost('DFakeP0st01', ['a@example.test', 'b@example.test']);

        $this->actingAs($a)->put("/posts/{$post->id}/note", ['note' => "  第一行\r\n<script>x</script>  "])
            ->assertRedirect("/posts/{$post->id}")->assertSessionHas('status', '備註已儲存。');
        $this->assertSame("第一行\n<script>x</script>", $this->entry($a, $post)->note);

        $html = $this->actingAs($a)->get("/posts/{$post->id}")->getContent();
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->actingAs($b)->get("/posts/{$post->id}")->assertOk()->assertDontSee('第一行');

        // 1000 characters (CRLF counted as one line break) ok, 1001 rejected without changing the note.
        $ok = str_repeat('字', 998)."\r\n字";
        $this->actingAs($a)->put("/posts/{$post->id}/note", ['note' => $ok])->assertSessionHasNoErrors();
        $this->assertSame(1000, mb_strlen($this->entry($a, $post)->note));
        $this->from("/posts/{$post->id}")->put("/posts/{$post->id}/note", ['note' => str_repeat('字', 1001)])
            ->assertSessionHasErrors(['note'], null, 'note');
        $this->assertSame(1000, mb_strlen($this->entry($a, $post)->note));

        // Empty clears.
        $this->put("/posts/{$post->id}/note", ['note' => '   '])->assertRedirect();
        $this->assertNull($this->entry($a, $post)->note);
    }

    public function test_index_card_shows_own_tags_as_filter_links(): void
    {
        $a = $this->user();
        $post = $this->makePost();
        $this->actingAs($a)->put("/posts/{$post->id}/tags", ['tags' => '活動 A'])->assertRedirect();

        $this->get('/')->assertOk()
            ->assertSee('#活動 A')
            ->assertSee(route('posts.index', ['tag' => ['活動 A']]), false);
    }

    public function test_untracking_removes_tags_no_longer_used(): void
    {
        $a = $this->user();
        $p1 = $this->makePost('DFakeP0st01');
        $p2 = $this->makePost('DFakeP0st02');
        $this->actingAs($a)->put("/posts/{$p1->id}/tags", ['tags' => 'only-p1, shared'])->assertRedirect();
        $this->put("/posts/{$p2->id}/tags", ['tags' => 'shared'])->assertRedirect();

        $this->delete("/posts/{$p1->id}")->assertRedirect('/');

        $this->assertSame(['shared'], $a->tags()->pluck('name')->all());
        $this->assertSame(0, DB::table('tag_tracked_post_user')->whereNotIn('tracked_post_user_id', DB::table('tracked_post_user')->select('id'))->count());
    }

    public function test_deleting_a_user_deletes_their_tags(): void
    {
        $a = $this->user();
        $post = $this->makePost();
        $this->actingAs($a)->put("/posts/{$post->id}/tags", ['tags' => 'x'])->assertRedirect();

        app(\App\Threads\TrackPostService::class)->deleteUser($a);

        $this->assertSame(0, Tag::count());
        $this->assertSame(0, DB::table('tag_tracked_post_user')->count());
    }
}
