<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration 000005 (groups) on a real SQLite file with foreign keys on, run the way production runs
 * it (no wrapping test transaction): adding tracked_post_user.group_id rebuilds that table, and the
 * rebuild must not cascade-delete tag links, notes or entries. Rolling back must not either.
 */
class GroupsMigrationTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'tm-groups-mig-');
        config(['database.connections.sqlite.database' => $this->file, 'database.connections.sqlite.foreign_key_constraints' => true]);
        DB::purge('sqlite');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function test_adding_and_removing_groups_keeps_every_existing_row(): void
    {
        $before = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($f) => ! str_contains($f, '2026_10_07_000005_add_groups')));
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $before, '--realpath' => true, '--force' => true]));
        $this->assertFalse(Schema::hasTable('groups'));
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));

        $now = now();
        $uid = DB::table('users')->insertGetId(['name' => 'u', 'email' => 'u@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $pid = DB::table('tracked_posts')->insertGetId(['url' => 'u', 'shortcode' => 'DFakeP0st01', 'created_at' => $now, 'updated_at' => $now]);
        $eid = DB::table('tracked_post_user')->insertGetId(['user_id' => $uid, 'tracked_post_id' => $pid, 'note' => 'kept', 'created_at' => $now, 'updated_at' => $now]);
        $tid = DB::table('tags')->insertGetId(['user_id' => $uid, 'name' => 'kept-tag', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tag_tracked_post_user')->insert(['tag_id' => $tid, 'tracked_post_user_id' => $eid]);

        $snapshot = fn () => [
            DB::table('tracked_post_user')->select('id', 'user_id', 'tracked_post_id', 'note')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('tag_tracked_post_user')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('tags')->count(), DB::table('tracked_posts')->count(), DB::table('users')->count(),
        ];
        $expected = $snapshot();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('groups'));
        $this->assertTrue(Schema::hasColumn('tracked_post_user', 'group_id'));
        $this->assertSame($expected, $snapshot());
        $this->assertNull(DB::table('tracked_post_user')->value('group_id'));
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));

        // Deleting a group sets its entries back to NULL (foreign key, nullOnDelete).
        $gid = DB::table('groups')->insertGetId(['user_id' => $uid, 'name' => 'G', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tracked_post_user')->where('id', $eid)->update(['group_id' => $gid]);
        DB::table('groups')->where('id', $gid)->delete();
        $this->assertNull(DB::table('tracked_post_user')->value('group_id'));
        $this->assertSame($expected, $snapshot());

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $this->assertFalse(Schema::hasTable('groups'));
        $this->assertFalse(Schema::hasColumn('tracked_post_user', 'group_id'));
        $this->assertSame($expected, $snapshot());
    }
}
