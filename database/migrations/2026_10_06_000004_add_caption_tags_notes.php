<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post caption (shared per tracked post), plus per-user notes and tags.
 *
 * Notes and tags belong to "one user's entry for one post" (a tracked_post_user row), so two
 * accounts tracking the same post never see each other's notes or tags.
 * Additive only: no existing column or row is changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_posts', function (Blueprint $table) {
            $table->text('caption')->nullable();
        });

        Schema::table('tracked_post_user', function (Blueprint $table) {
            $table->text('note')->nullable();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 64);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('tag_tracked_post_user', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignId('tracked_post_user_id')->constrained('tracked_post_user')->cascadeOnDelete();

            $table->unique(['tag_id', 'tracked_post_user_id']);
            $table->index('tracked_post_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_tracked_post_user');
        Schema::dropIfExists('tags');

        Schema::table('tracked_post_user', function (Blueprint $table) {
            $table->dropColumn('note');
        });

        Schema::table('tracked_posts', function (Blueprint $table) {
            $table->dropColumn('caption');
        });
    }
};
