<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_admin');
        });

        Schema::create('tracked_post_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tracked_post_id')->constrained('tracked_posts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'tracked_post_id']);
            $table->index('tracked_post_id');
        });

        // Before this migration the site had a single owner account: it becomes admin
        // and keeps every post it was already tracking (otherwise they would vanish from its list).
        DB::table('users')->update(['is_admin' => true]);

        $now = now();
        $userIds = DB::table('users')->pluck('id');
        DB::table('tracked_posts')->orderBy('id')->pluck('id')->chunk(500)->each(function ($postIds) use ($userIds, $now) {
            $rows = [];
            foreach ($userIds as $uid) {
                foreach ($postIds as $pid) {
                    $rows[] = ['user_id' => $uid, 'tracked_post_id' => $pid, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            if ($rows !== []) {
                DB::table('tracked_post_user')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_post_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'is_active']);
        });
    }
};
