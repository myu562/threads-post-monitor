<?php

namespace Tests\Feature;

use App\Models\TrackedPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Contracts K4 (search / filter within the user's own list) and K5 (migration). */
class PostSearchTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $email = 'a@example.test'): User
    {
        return User::firstOrCreate(['email' => $email], ['name' => 'u', 'password' => 'correct-horse-battery']);
    }

    private function makePost(array $attrs = [], string $owner = 'a@example.test', ?string $note = null, array $tags = []): TrackedPost
    {
        $this->seq++;
        $code = 'DFakeSrch'.str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT);
        $post = TrackedPost::create(array_merge([
            'url' => 'https://www.threads.com/t/'.$code, 'shortcode' => $code, 'username' => 'user_'.$this->seq,
            'published_at' => CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'), 'published_at_source' => 'page',
            'track_until' => CarbonImmutable::parse('2026-10-08 17:00:00', 'UTC'), 'status' => 'tracking',
        ], $attrs));
        $user = $this->user($owner);
        $user->trackedPosts()->attach($post->id, ['note' => $note]);
        if ($tags !== []) {
            $this->actingAs($user)->put("/posts/{$post->id}/tags", ['tags' => implode(',', $tags)])->assertRedirect();
        }

        return $post;
    }

    /** Ids of the posts listed on the page, in order. */
    private function listed(string $query): array
    {
        $html = $this->actingAs($this->user())->get('/'.$query)->assertOk()->getContent();
        preg_match_all('/data-post="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    public function test_keyword_matches_username_shortcode_caption_note_and_tag_case_insensitively(): void
    {
        $byUser = $this->makePost(['username' => 'Coffee_Shop']);
        $byCode = $this->makePost(['shortcode' => 'DFakeXyZ999', 'url' => 'https://www.threads.com/t/DFakeXyZ999']);
        $byCaption = $this->makePost(['caption' => "第一行\n今天的 SPECIAL 活動"]);
        $byNote = $this->makePost([], 'a@example.test', '客戶 Alpha 的合作稿');
        $byTag = $this->makePost([], 'a@example.test', null, ['GammaTag']);
        $none = $this->makePost(['caption' => 'nothing here']);

        $this->assertSame([$byUser->id], $this->listed('?q=coffee'));
        $this->assertSame([$byCode->id], $this->listed('?q=xyz9'));
        $this->assertSame([$byCaption->id], $this->listed('?q=special'));
        $this->assertSame([$byNote->id], $this->listed('?q=ALPHA'));
        $this->assertSame([$byTag->id], $this->listed('?q=gammat'));
        $this->assertSame([$byCaption->id], $this->listed('?q='.urlencode('今天的')));
        $this->assertCount(6, $this->listed('?q='));
        $this->assertSame([$none->id], $this->listed('?q=NOTHING'));
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $pct = $this->makePost(['caption' => '打 50% 折']);
        $under = $this->makePost(['caption' => 'snake_case word']);
        $bang = $this->makePost(['caption' => 'wow! nice']);
        $this->makePost(['caption' => 'plain text 50 off']);
        $this->makePost(['caption' => 'snakeXcase']);

        $this->assertSame([$pct->id], $this->listed('?q='.urlencode('50%')));
        $this->assertSame([$under->id], $this->listed('?q='.urlencode('e_c')));
        $this->assertSame([$pct->id], $this->listed('?q='.urlencode('%')));  // literal percent, not "anything"
        $this->assertSame([$bang->id], $this->listed('?q='.urlencode('wow!')));
    }

    public function test_tags_filter_is_and_and_status_filter_works(): void
    {
        $both = $this->makePost(['status' => 'done'], 'a@example.test', null, ['紅', 'Blue']);
        $red = $this->makePost(['status' => 'tracking'], 'a@example.test', null, ['紅']);
        $blue = $this->makePost(['status' => 'failed'], 'a@example.test', null, ['Blue']);

        $this->assertEqualsCanonicalizing([$both->id, $red->id], $this->listed('?tag[]='.urlencode('紅')));
        $this->assertSame([$both->id], $this->listed('?tag[]='.urlencode('紅').'&tag[]=blue'));
        $this->assertSame([$both->id], $this->listed('?tag[]=BLUE&status=done'));
        $this->assertSame([$blue->id], $this->listed('?status=failed'));
        $this->assertSame([], $this->listed('?status=gone'));
        $this->assertCount(3, $this->listed('?status=bogus'));            // invalid value is ignored
        $this->assertSame([], $this->listed('?tag[]=unknown'));            // unknown tag: nothing matches
        $this->assertCount(3, $this->listed('?tag[]='));                   // empty select value ignored
        $this->assertSame([$red->id], $this->listed('?tag='.urlencode('紅').'&q=user_2')); // single value + q
    }

    public function test_search_only_covers_own_list_and_own_notes_and_tags(): void
    {
        $mine = $this->makePost(['caption' => 'shared words']);
        $this->makePost(['caption' => 'shared words'], 'b@example.test');
        // Same post tracked by both: B's note and tag must not make it match for A.
        $both = $this->makePost(['caption' => 'x']);
        $this->user('b@example.test')->trackedPosts()->attach($both->id, ['note' => 'b-secret-note']);
        $this->actingAs($this->user('b@example.test'))->put("/posts/{$both->id}/tags", ['tags' => 'bsecrettag'])->assertRedirect();

        $this->assertSame([$mine->id], $this->listed('?q=shared'));
        $this->assertSame([], $this->listed('?q=b-secret'));
        $this->assertSame([], $this->listed('?q=bsecret'));
        $this->assertSame([], $this->listed('?tag[]=bsecrettag'));
    }

    public function test_applied_conditions_clear_link_and_empty_message(): void
    {
        $this->makePost([], 'a@example.test', null, ['活動']);

        $this->actingAs($this->user())->get('/?q=nomatch&status=done&tag[]='.urlencode('活動'))->assertOk()
            ->assertSee('目前條件')
            ->assertSee('關鍵字「nomatch」')
            ->assertSee('狀態：已完成')
            ->assertSee('#活動')
            ->assertSee('清除篩選')
            ->assertSee('沒有符合條件的貼文')
            ->assertDontSee('還沒有追蹤任何貼文');

        $this->get('/?q='.urlencode('<b>x</b>'))->assertOk()->assertSee('&lt;b&gt;x&lt;/b&gt;', false)->assertDontSee('<b>x</b>', false);
        $this->get('/')->assertOk()->assertDontSee('目前條件');
    }

    public function test_query_count_does_not_grow_with_number_of_posts(): void
    {
        $seed = function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                $p = $this->makePost(['caption' => 'cap '.$i], 'a@example.test', 'note '.$i, ['t'.($i % 3), 'common']);
                foreach ([1, 2, 3] as $h) {
                    $p->snapshots()->create(['captured_at' => CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC')->addHours($h),
                        'hours_since_publish' => $h, 'source' => 'public_page', 'views' => 10 * $h, 'replies' => $h]);
                }
            }
        };
        $count = function (string $query): int {
            $this->actingAs($this->user());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/'.$query)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $seed(1);
        $one = $count('');
        $oneFiltered = $count('?q=cap&tag[]=common');
        $seed(9);
        $this->assertCount(10, $this->listed(''));
        $this->assertSame($one, $count(''));
        $this->assertSame($oneFiltered, $count('?q=cap&tag[]=common'));
        $this->assertLessThanOrEqual(12, $one);
    }

    public function test_migration_is_additive_and_reversible(): void
    {
        $u = $this->user();
        $p = $this->makePost(['caption' => 'c'], 'a@example.test', 'n', ['t']);
        $p->snapshots()->create(['captured_at' => now(), 'source' => 'public_page', 'views' => 5]);

        // Roll back 000005 (groups) and 000004 (caption / tags / notes).
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('tags'));
        $this->assertFalse(Schema::hasTable('tag_tracked_post_user'));
        $this->assertFalse(Schema::hasColumn('tracked_posts', 'caption'));
        $this->assertFalse(Schema::hasColumn('tracked_post_user', 'note'));
        // Existing data survives the round trip.
        $this->assertSame(1, DB::table('tracked_posts')->count());
        $this->assertSame(1, DB::table('tracked_post_user')->where('user_id', $u->id)->count());
        $this->assertSame(1, DB::table('post_snapshots')->count());

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('tracked_posts', 'caption'));
        $this->assertTrue(Schema::hasColumn('tracked_post_user', 'note'));
        $this->assertTrue(Schema::hasTable('tags'));
        $this->assertSame(['id', 'user_id', 'name', 'created_at', 'updated_at'], Schema::getColumnListing('tags'));
        $this->assertEqualsCanonicalizing(['tag_id', 'tracked_post_user_id'], Schema::getColumnListing('tag_tracked_post_user'));
        $this->assertSame(1, DB::table('tracked_posts')->count());
        $this->assertNull(DB::table('tracked_posts')->value('caption'));
    }
}
