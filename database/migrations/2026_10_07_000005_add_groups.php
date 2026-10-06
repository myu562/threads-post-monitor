<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user groups. A group belongs to one account; a post sits in at most one group per account,
 * stored on that account's entry (tracked_post_user.group_id). Deleting a group sets its entries'
 * group_id back to NULL (Ungrouped).
 *
 * Additive only: no existing column or row is changed or removed.
 *
 * Not run inside a transaction: on SQLite, adding a foreign key rebuilds tracked_post_user
 * (copy, drop, rename), and Laravel switches foreign keys off around that rebuild so dropping the
 * old table cannot cascade into tag_tracked_post_user. SQLite ignores "PRAGMA foreign_keys" inside a
 * transaction, so the switch is only guaranteed to take effect outside one.
 * tests/Feature/GroupsMigrationTest checks on a real SQLite file that no row is lost either way.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 64);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::table('tracked_post_user', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tracked_post_user', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropColumn('group_id');
        });

        Schema::dropIfExists('groups');
    }
};
