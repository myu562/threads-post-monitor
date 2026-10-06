<?php

namespace App\Console\Commands;

use App\Models\TrackedPost;
use App\Threads\PostPoller;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polls every tracked post whose next_poll_at is due, one by one in this process.
 * Each post is isolated by try/catch, so one failure never stops the rest.
 */
class PollDue extends Command
{
    protected $signature = 'threads:poll-due {--limit=60 : Max posts to poll in this run}';

    protected $description = 'Fetch due Threads posts and record snapshots / final numbers';

    public function handle(PostPoller $poller): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) config('threads.politeness_sleep_ms', 3000));
        $now = CarbonImmutable::now();

        $posts = TrackedPost::query()
            ->whereIn('status', [TrackedPost::STATUS_PENDING, TrackedPost::STATUS_TRACKING])
            ->whereNotNull('next_poll_at')
            ->where('next_poll_at', '<=', $now)
            ->orderBy('next_poll_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $polled = 0;
        $errors = 0;
        foreach ($posts as $i => $post) {
            if ($i > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
            try {
                $poller->poll($post);
                $polled++;
            } catch (Throwable $e) {
                // PostPoller is not supposed to throw; this is a last line of defence.
                $errors++;
                Log::error('threads:poll-due post failed', [
                    'tracked_post_id' => $post->id,
                    'error' => class_basename($e).': '.$e->getMessage(),
                ]);
            }
        }

        $this->info("Polled {$polled} post(s), {$errors} error(s), due candidates {$posts->count()} (limit {$limit}).");

        return self::SUCCESS;
    }
}
