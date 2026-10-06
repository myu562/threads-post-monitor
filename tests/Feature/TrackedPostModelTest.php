<?php

namespace Tests\Feature;

use App\Models\PostSnapshot;
use App\Models\TrackedPost;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrackedPostModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_have_contract_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('tracked_posts', [
            'id', 'url', 'shortcode', 'username', 'source', 'pk', 'published_at', 'published_at_source',
            'track_until', 'next_poll_at', 'status', 'added_after_window', 'counts_hidden',
            'final_views', 'final_likes', 'final_replies', 'final_reposts', 'final_quotes', 'final_shares',
            'final_captured_at', 'last_captured_at', 'last_error', 'consecutive_failures', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('post_snapshots', [
            'id', 'tracked_post_id', 'captured_at', 'hours_since_publish',
            'views', 'likes', 'replies', 'reposts', 'quotes', 'shares', 'source', 'raw_json', 'created_at', 'updated_at',
        ]));
    }

    public function test_defaults_casts_and_cascade(): void
    {
        $post = TrackedPost::create([
            'url' => 'https://www.threads.com/t/DFakeP0st01',
            'shortcode' => 'DFakeP0st01',
            'published_at' => CarbonImmutable::parse('2026-10-03 08:00:00', 'UTC'),
        ])->refresh();

        $this->assertSame('pending', $post->status);
        $this->assertSame('public_page', $post->source);
        $this->assertFalse($post->counts_hidden);
        $this->assertSame(0, $post->consecutive_failures);
        $this->assertInstanceOf(CarbonImmutable::class, $post->published_at);
        $this->assertNull($post->final_shares);

        $post->snapshots()->create([
            'captured_at' => CarbonImmutable::now('UTC'), 'source' => 'public_page',
            'likes' => 3, 'shares' => null, 'hours_since_publish' => 1.5,
        ]);
        $this->assertSame(1, $post->snapshots()->count());
        $this->assertNull(PostSnapshot::first()->shares);

        $post->delete();
        $this->assertSame(0, PostSnapshot::count());
    }

    public function test_shortcode_is_unique(): void
    {
        TrackedPost::create(['url' => 'a', 'shortcode' => 'DFakeP0st01']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        TrackedPost::create(['url' => 'b', 'shortcode' => 'DFakeP0st01']);
    }
}
