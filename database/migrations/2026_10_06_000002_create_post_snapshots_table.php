<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_post_id')->constrained('tracked_posts')->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->decimal('hours_since_publish', 8, 2)->nullable();
            $table->unsignedBigInteger('views')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('replies')->nullable();
            $table->unsignedBigInteger('reposts')->nullable();
            $table->unsignedBigInteger('quotes')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->string('source');
            $table->longText('raw_json')->nullable();
            $table->timestamps();

            $table->index(['tracked_post_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_snapshots');
    }
};
