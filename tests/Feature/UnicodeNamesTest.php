<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Tag;
use App\Models\TrackedPost;
use App\Models\TrackedPostUser;
use App\Models\User;
use App\Support\TextKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * RA-F3: search folds non-ASCII case on SQLite too.
 * RA-F7: group and tag names follow the database collation (utf8mb4_unicode_ci on MySQL): the
 * PHP comparison key folds accents and width, and a unique-index rejection is never a 500.
 */
class UnicodeNamesTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $email = 'a@example.test'): User
    {
        return User::firstOrCreate(['email' => $email], ['name' => 'u', 'password' => 'correct-horse-battery']);
    }

    private function mkpost(array $attrs = [], ?User $owner = null): TrackedPost
    {
        $this->seq++;
        $code = 'DFakeUni'.str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT);
        $post = TrackedPost::create(array_merge([
            'url' => 'https://www.threads.com/t/'.$code, 'shortcode' => $code, 'username' => 'user_'.$this->seq,
            'published_at' => CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'), 'published_at_source' => 'page',
            'track_until' => CarbonImmutable::parse('2026-10-08 17:00:00', 'UTC'), 'status' => 'tracking',
        ], $attrs));
        ($owner ?? $this->user())->trackedPosts()->attach($post->id);

        return $post;
    }

    private function entry(TrackedPost $p, ?User $u = null): TrackedPostUser
    {
        return TrackedPostUser::where('user_id', ($u ?? $this->user())->id)->where('tracked_post_id', $p->id)->sole();
    }

    private function hits(string $q, TrackedPost $p): bool
    {
        $html = $this->actingAs($this->user())->get('/?q='.urlencode($q))->assertOk()->getContent();

        return str_contains($html, 'data-post="'.$p->id.'"');
    }

    /** Simulate MySQL's unique index rejecting an insert (the exception Laravel raises for it). */
    private static function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException('mysql', 'insert into ...', [], new RuntimeException('Duplicate entry'));
    }

    // ---- RA-F3 ----

    public function test_sqlite_lower_is_unicode_aware(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame('école ünïcode αβγ', DB::scalar("select lower('École ÜNÏCODE ΑΒΓ')"));
    }

    public function test_search_folds_non_ascii_case_in_caption(): void
    {
        $p = $this->mkpost(['caption' => 'École Ünïcode ΑΒΓ']);
        foreach (['école', 'ÉCOLE', 'École', 'ünïcode', 'ÜNÏCODE', 'αβγ'] as $q) {
            $this->assertTrue($this->hits($q, $p), $q);
        }
        $this->assertFalse($this->hits('ecole', $this->mkpost(['caption' => 'nothing here'])));
    }

    public function test_search_folds_non_ascii_case_in_note_tag_and_group(): void
    {
        $byNote = $this->mkpost();
        $byTag = $this->mkpost();
        $byGroup = $this->mkpost();
        $this->actingAs($this->user())->put("/posts/{$byNote->id}/meta", ['note' => 'ÉTUDE privée'])->assertRedirect();
        $this->actingAs($this->user())->put("/posts/{$byTag->id}/meta", ['tags' => 'ΔΕΛΤΑ'])->assertRedirect();
        $this->actingAs($this->user())->put("/posts/{$byGroup->id}/meta", ['group' => 'new', 'new_group' => 'ÜBERGRUPPE'])->assertRedirect();

        $this->assertTrue($this->hits('étude', $byNote));
        $this->assertTrue($this->hits('δελτα', $byTag));
        $this->assertTrue($this->hits('übergruppe', $byGroup));
        $this->assertFalse($this->hits('übergruppe', $byNote));
    }

    public function test_group_name_search_only_sees_the_users_own_group(): void
    {
        $other = $this->user('b@example.test');
        $shared = $this->mkpost();
        $other->trackedPosts()->attach($shared->id);
        $this->actingAs($other)->put("/posts/{$shared->id}/meta", ['group' => 'new', 'new_group' => 'Geheim'])->assertRedirect();

        $this->assertFalse($this->hits('geheim', $shared));
    }

    // ---- RA-F7: comparison key ----

    public function test_name_key_folds_accents_width_and_case(): void
    {
        $this->assertSame('cafe', TextKey::fold('Café'));
        $this->assertSame('cafe', TextKey::fold("\u{FF23}\u{FF21}\u{FF26}\u{FF25}"));
        $this->assertSame('istanbul', TextKey::fold('İstanbul'));
        $this->assertSame(Group::key('Résumé'), Group::key('RESUME'));
        $this->assertSame(Tag::key('Ñandú'), Tag::key('nandu'));
    }

    public function test_accent_variants_are_one_group_and_one_tag(): void
    {
        $u = $this->user();
        $p = $this->mkpost();
        $this->actingAs($u)->post('/groups', ['name' => 'Café'])->assertRedirect();
        $this->actingAs($u)->post('/groups', ['name' => 'CAFE'])->assertRedirect()->assertSessionHasErrors('name', null, 'groups');
        $this->assertSame(['Café'], Group::pluck('name')->all());

        $this->actingAs($u)->put("/posts/{$p->id}/meta", ['group' => 'new', 'new_group' => 'cafe'])->assertRedirect();
        $this->assertSame('Café', $this->entry($p)->group->name);

        $this->actingAs($u)->put("/posts/{$p->id}/meta", ['tags' => 'café,CAFE,İstanbul,istanbul'])->assertRedirect();
        $this->assertSame(['café', 'İstanbul'], Tag::orderBy('id')->pluck('name')->all());
    }

    // ---- RA-F7: unique index rejects what PHP let through (ß = ss under unicode_ci) ----

    public function test_new_group_rejected_by_unique_index_is_name_taken_not_500(): void
    {
        $u = $this->user();
        $u->groups()->create(['name' => 'Strasse', 'sort_order' => 1]);
        Group::creating(function (Group $g) {
            if ($g->name === 'Straße') {
                throw self::uniqueViolation();
            }
        });

        $this->actingAs($u)->post('/groups', ['name' => 'Straße'])
            ->assertRedirect()
            ->assertSessionHasErrors(['name' => __('posts.groups.name_taken', ['name' => 'Straße'])], null, 'groups');
        $this->assertSame(1, Group::count());
    }

    public function test_rename_rejected_by_unique_index_is_name_taken_not_500(): void
    {
        $u = $this->user();
        $u->groups()->create(['name' => 'Strasse', 'sort_order' => 1]);
        $g = $u->groups()->create(['name' => 'Other', 'sort_order' => 2]);
        Group::updating(function (Group $g) {
            if ($g->name === 'Straße') {
                throw self::uniqueViolation();
            }
        });

        $this->actingAs($u)->put("/groups/{$g->id}", ['name' => 'Straße'])
            ->assertRedirect()
            ->assertSessionHasErrors(['name' => __('posts.groups.name_taken', ['name' => 'Straße'])], null, 'groups');
        $this->assertSame('Other', $g->fresh()->name);
    }

    /**
     * Another request inserts $table.name = $name right after this request's last read of the
     * user's rows, inside its transaction but before the insert's savepoint: our insert then hits
     * the real unique index (a genuine UniqueConstraintViolationException from SQLite).
     */
    private function raceInsert(string $table, array $row): void
    {
        $done = false;
        DB::listen(function ($query) use (&$done, $table, $row) {
            if (! $done && DB::transactionLevel() >= 2 && str_starts_with($query->sql, 'select * from "'.$table.'"')) {
                $done = true;
                DB::table($table)->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function test_new_group_option_reuses_the_group_the_database_matched(): void
    {
        $u = $this->user();
        $p = $this->mkpost();
        $this->raceInsert('groups', ['user_id' => $u->id, 'name' => 'Straße', 'sort_order' => 9]);

        $this->actingAs($u)->put("/posts/{$p->id}/meta", ['group' => 'new', 'new_group' => 'Straße', 'note' => 'kept'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Group::count());
        $this->assertSame('Straße', $this->entry($p)->group->name);
        $this->assertSame('kept', $this->entry($p)->note);
    }

    public function test_new_group_option_without_a_findable_match_is_an_error_and_saves_nothing(): void
    {
        $u = $this->user();
        $p = $this->mkpost();
        $u->groups()->create(['name' => 'Strasse', 'sort_order' => 1]); // MySQL would match this; SQLite cannot
        Group::creating(function (Group $g) {
            if ($g->name === 'Straße') {
                throw self::uniqueViolation();
            }
        });

        $this->actingAs($u)->from('/')->put("/posts/{$p->id}/meta", ['group' => 'new', 'new_group' => 'Straße', 'note' => 'not saved', 'redirect' => '/'])
            ->assertRedirect('/')
            ->assertSessionHasErrors(['new_group'], null, 'meta');
        $this->assertNull($this->entry($p)->group_id);
        $this->assertNull($this->entry($p)->note);
    }

    public function test_tag_rejected_by_unique_index_reuses_the_existing_tag(): void
    {
        $u = $this->user();
        $p = $this->mkpost();
        $this->raceInsert('tags', ['user_id' => $u->id, 'name' => 'Straße']);

        $this->actingAs($u)->put("/posts/{$p->id}/meta", ['tags' => 'Straße,other'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Tag::where('name', 'Straße')->count());
        $this->assertEqualsCanonicalizing(['Straße', 'other'], $this->entry($p)->tags()->pluck('name')->all());
    }

    public function test_tag_rejected_without_a_findable_match_is_an_error_not_500(): void
    {
        $u = $this->user();
        $p = $this->mkpost();
        Tag::creating(function (Tag $t) {
            if ($t->name === 'Straße') {
                throw self::uniqueViolation();
            }
        });

        $this->actingAs($u)->put("/posts/{$p->id}/tags", ['tags' => 'Straße'])
            ->assertRedirect()
            ->assertSessionHasErrors(['tags' => __('posts.tags.name_conflict', ['name' => 'Straße'])], null, 'tags');
        $this->actingAs($u)->put("/posts/{$p->id}/meta", ['tags' => 'Straße'])
            ->assertRedirect()->assertSessionHasErrors(['tags'], null, 'meta');
        $this->assertSame(0, Tag::count());
    }
}
