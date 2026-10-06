<?php

namespace App\Threads;

use App\Models\TrackedPost;
use App\Models\User;
use App\Support\InvalidInput;
use App\Threads\Exceptions\ShareResolveException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Adds / removes Threads posts to a user's own tracking list.
 *
 * tracked_posts is unique per shortcode and is what the poller works on; users only own posts
 * through the tracked_post_user pivot. So when a second user adds a post someone already tracks,
 * nothing is re-created, reset or fetched: they just share the existing row and its snapshots.
 */
final class TrackPostService
{
    /** True when the last register() call added the post to the user's list (it was not there before). */
    public bool $lastAttached = false;

    public function __construct(private readonly ShareUrlResolver $shareResolver) {}

    /**
     * Accepts a post URL or a /share/ link (resolved with one HTTP request). Only the cleaned post
     * URL is stored: never the share link or tracking query (xmt, slof, igshid…), which identify
     * who shared it.
     *
     * @throws InvalidInput when the URL is not a Threads post or share link (message: a translation key)
     * @throws ShareResolveException when a share link cannot be resolved
     */
    public function register(string $url, User $user): TrackedPost
    {
        $parsed = ThreadsUrl::parse($url);
        if ($parsed === null && ($shareCode = ThreadsUrl::parseShare($url)) !== null) {
            $parsed = ThreadsUrl::parse($this->shareResolver->resolve($shareCode));
        }
        if ($parsed === null) {
            throw new InvalidInput('errors.url.unrecognized');
        }

        $shortcode = $parsed['shortcode'];

        return DB::transaction(function () use ($parsed, $shortcode, $user) {
            $post = TrackedPost::firstOrCreate(
                ['shortcode' => $shortcode],
                (function () use ($parsed, $shortcode) {
                    $publishedAt = Shortcode::createdAt($shortcode);

                    return [
                        'url' => ThreadsUrl::clean($shortcode, $parsed['username']),
                        'username' => $parsed['username'],
                        'source' => 'public_page',
                        'pk' => Shortcode::toPk($shortcode),
                        'published_at' => $publishedAt,
                        'published_at_source' => TrackedPost::PUBLISHED_FROM_SHORTCODE,
                        'track_until' => $publishedAt->addHours((int) config('threads.track_hours')),
                        'status' => TrackedPost::STATUS_PENDING,
                        'next_poll_at' => CarbonImmutable::now('UTC'),
                    ];
                })()
            );

            $changes = $user->trackedPosts()->syncWithoutDetaching([$post->id]);
            $this->lastAttached = $changes['attached'] !== [];

            return $post;
        });
    }

    /**
     * Remove the post from the user's list; delete it (snapshots cascade) once nobody owns it.
     *
     * @return bool true when the tracked_post itself was deleted (the user was the last owner)
     */
    public function untrack(TrackedPost $post, User $user): bool
    {
        return DB::transaction(function () use ($post, $user) {
            $user->trackedPosts()->detach($post->id);

            return $this->deleteIfOrphaned([$post->id]) > 0;
        });
    }

    /** Delete a user and every post that only that user was tracking. */
    public function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            $postIds = $user->trackedPosts()->pluck('tracked_posts.id')->all();
            $user->trackedPosts()->detach();
            $user->delete();
            $this->deleteIfOrphaned($postIds);
        });
    }

    /**
     * @param  list<int>  $postIds
     * @return int number of tracked_posts deleted
     */
    private function deleteIfOrphaned(array $postIds): int
    {
        if ($postIds === []) {
            return 0;
        }

        $orphans = TrackedPost::whereIn('id', $postIds)->whereDoesntHave('users')->get();
        $orphans->each(fn (TrackedPost $p) => $p->delete());

        return $orphans->count();
    }
}
