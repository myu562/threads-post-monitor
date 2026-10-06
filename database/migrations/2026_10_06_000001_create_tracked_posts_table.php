<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_posts', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->string('shortcode')->unique();
            $table->string('username')->nullable();
            $table->string('source')->default('public_page');
            $table->string('pk')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('published_at_source')->nullable(); // page | shortcode_decode
            $table->timestamp('track_until')->nullable();
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->string('status')->default('pending'); // pending|tracking|done|gone|failed
            $table->boolean('added_after_window')->default(false);
            $table->boolean('counts_hidden')->default(false);
            $table->unsignedBigInteger('final_views')->nullable();
            $table->unsignedBigInteger('final_likes')->nullable();
            $table->unsignedBigInteger('final_replies')->nullable();
            $table->unsignedBigInteger('final_reposts')->nullable();
            $table->unsignedBigInteger('final_quotes')->nullable();
            $table->unsignedBigInteger('final_shares')->nullable();
            $table->timestamp('final_captured_at')->nullable();
            $table->timestamp('last_captured_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_posts');
    }
};
