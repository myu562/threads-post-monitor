<?php

namespace App\Threads;

use App\Models\PostSnapshot;
use App\Models\TrackedPost;
use App\Support\StoredMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches one tracked post and advances its state machine.
 *
 * Never throws: any unexpected exception is recorded on the post as an 'exception' failure.
 * NULL discipline: snapshot and final_* metrics are copied verbatim; null means "not available".
 */
class PostPoller
{
    public const STATUS_EXCEPTION = 'exception';

    public const STATUS_NO_PUBLISH_TIME = 'no_publish_time';

    private const METRICS = ['views', 'likes', 'replies', 'reposts', 'quotes', 'shares'];

    public function __construct(
        private readonly PublicPageFetcher $fetcher,
        private readonly PollPlanner $planner,
    ) {}

    public function poll(TrackedPost $post, ?CarbonImmutable $now = null): void
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        try {
            $result = $this->fetcher->fetch($post->shortcode);

            if ($result->ok) {
                DB::transaction(fn () => $this->handleSuccess($post, $result, $now));
            } elseif ($result->status === FetchResult::GONE) {
                DB::transaction(fn () => $this->handleGone($post, self::stored($result)));
            } else {
                DB::transaction(fn () => $this->handleFailure($post, self::stored($result), $now));
            }
        } catch (Throwable $e) {
            try {
                $post->refresh(); // drop any half-applied in-memory changes
                // The full message can carry internals (SQL, paths, URLs): it goes to the server log
                // only; the page shows the exception class.
                Log::warning('threads: unexpected exception while polling', [
                    'tracked_post_id' => $post->id,
                    'error' => self::oneLine(class_basename($e).': '.$e->getMessage()),
                ]);
                $message = StoredMessage::encode(self::STATUS_EXCEPTION, ['class' => class_basename($e)]);
                DB::transaction(fn () => $this->handleFailure($post, $message, $now));
            } catch (Throwable $inner) {
                Log::error('threads: could not record poll failure', [
                    'tracked_post_id' => $post->id,
                    'error' => self::oneLine(class_basename($inner).': '.$inner->getMessage()),
                ]);
            }
        }
    }

    private function handleSuccess(TrackedPost $post, FetchResult $result, CarbonImmutable $now): void
    {
        $data = $result->data;

        // 1. Page publish time wins over the shortcode-decoded estimate.
        if ($post->published_at_source !== TrackedPost::PUBLISHED_FROM_PAGE && $data->takenAt !== null) {
            $post->published_at = $data->takenAt->utc();
            $post->published_at_source = TrackedPost::PUBLISHED_FROM_PAGE;
            $post->track_until = $this->planner->trackUntil($post->published_at);
        }
        if ($post->published_at !== null && $post->track_until === null) {
            $post->track_until = $this->planner->trackUntil($post->published_at);
        }
        // The username in the submitted URL is only a placeholder (anyone can type any @name before
        // /post/CODE): the first successful page read replaces it with the real author.
        $isFirstSnapshot = ! $post->snapshots()->exists();
        if ($data->username !== null && ($isFirstSnapshot || blank($post->username)) && $post->username !== $data->username) {
            $post->username = $data->username;
            $post->url = ThreadsUrl::clean($post->shortcode, $data->username);
        }
        if (blank($post->pk) && $data->pk !== null) {
            $post->pk = $data->pk;
        }
        $post->counts_hidden = $data->countsHidden;
        // Caption is written once: the first non-null reading sticks (later edits are not tracked).
        if ($post->caption === null && $data->caption !== null) {
            $post->caption = $data->caption;
        }

        // 2. Snapshot.
        $snapshot = $post->snapshots()->create([
            'captured_at' => $now,
            'hours_since_publish' => $post->published_at !== null
                ? round(($now->getTimestamp() - $post->published_at->getTimestamp()) / 3600, 2)
                : null,
            'views' => $data->views,
            'likes' => $data->likes,
            'replies' => $data->replies,
            'reposts' => $data->reposts,
            'quotes' => $data->quotes,
            'shares' => $data->shares,
            'source' => 'public_page',
            'raw_json' => $result->rawExcerpt,
        ]);
        $post->last_captured_at = $now;

        // Defensive: without any publish time the 72h window is undefined. Keep the snapshot but
        // treat the poll as a failure so the post retries a bounded number of times, then fails visibly.
        if ($post->published_at === null) {
            $this->handleFailure($post, StoredMessage::encode('poll.'.self::STATUS_NO_PUBLISH_TIME), $now);

            return;
        }

        $post->consecutive_failures = 0;
        $post->last_error = null;
        $this->flagAddedAfterWindow($post);

        if ($now->greaterThanOrEqualTo($post->track_until)) {
            // 3. Window closed: this snapshot is the final reading.
            $this->writeFinal($post, $snapshot, $now);
            $post->status = TrackedPost::STATUS_DONE;
            $post->next_poll_at = null;
        } else {
            // 4. Keep tracking.
            $post->status = TrackedPost::STATUS_TRACKING;
            $post->next_poll_at = $this->planner->nextPollAt($post->published_at, $now);
        }

        $post->save();
    }

    // 5. Deleted or made private: stop, keep snapshots, final = last successful reading (its own time).
    private function handleGone(TrackedPost $post, string $storedError): void
    {
        $post->status = TrackedPost::STATUS_GONE;
        $post->next_poll_at = null;
        $post->last_error = $storedError;
        $this->writeFinalFromLastSnapshot($post);
        $post->save();
    }

    // 6. Any other failure (and 7. unexpected exceptions, stored with code 'exception').
    private function handleFailure(TrackedPost $post, string $storedError, CarbonImmutable $now): void
    {
        $post->consecutive_failures = (int) $post->consecutive_failures + 1;
        $post->last_error = $storedError;

        $trackUntil = $post->track_until;
        if ($trackUntil === null && $post->published_at !== null) {
            $trackUntil = $post->track_until = $this->planner->trackUntil($post->published_at);
        }
        $this->flagAddedAfterWindow($post);

        if ($trackUntil !== null && $now->greaterThanOrEqualTo($trackUntil)) {
            // 6a. The final poll failed: fall back to the last successful snapshot, at its real time.
            $post->status = $this->writeFinalFromLastSnapshot($post)
                ? TrackedPost::STATUS_DONE
                : TrackedPost::STATUS_FAILED;
            $post->next_poll_at = null;
        } elseif ($post->consecutive_failures >= (int) config('threads.max_consecutive_failures', 5)) {
            // 6b. Too many failures in a row.
            $post->status = TrackedPost::STATUS_FAILED;
            $post->next_poll_at = null;
            $this->writeFinalFromLastSnapshot($post);
        } else {
            // 6c. Retry soon, but never after track_until.
            $retry = $now->addMinutes((int) config('threads.retry_minutes', 15));
            $post->next_poll_at = ($trackUntil !== null && $retry->greaterThan($trackUntil)) ? $trackUntil : $retry;
        }

        $post->save();
    }

    /** The post was added to tracking only once its 72-hour window had already closed. */
    private function flagAddedAfterWindow(TrackedPost $post): void
    {
        if ($post->created_at !== null && $post->track_until !== null) {
            $post->added_after_window = $post->created_at->greaterThanOrEqualTo($post->track_until);
        }
    }

    private function writeFinalFromLastSnapshot(TrackedPost $post): bool
    {
        if (! $post->exists) {
            return false;
        }
        /** @var PostSnapshot|null $last */
        $last = $post->snapshots()->orderByDesc('captured_at')->orderByDesc('id')->first();
        if ($last === null) {
            return false;
        }
        $this->writeFinal($post, $last, $last->captured_at);

        return true;
    }

    private function writeFinal(TrackedPost $post, PostSnapshot $snapshot, CarbonImmutable $capturedAt): void
    {
        foreach (self::METRICS as $m) {
            $post->{'final_'.$m} = $snapshot->{$m};
        }
        $post->final_captured_at = $capturedAt;
    }

    /**
     * last_error for a failed fetch: the result's code and parameters (contract L4), turned into
     * text in the viewer's language when a page shows it. The state transition still depends only
     * on the FetchResult status; the English diagnostic that used to be stored goes to the log.
     */
    private static function stored(FetchResult $result): string
    {
        Log::info('threads: poll did not succeed', ['status' => $result->status, 'error' => $result->error]);

        return StoredMessage::encode($result->code ?? 'fetch.'.$result->status, $result->params);
    }

    private static function oneLine(string $s): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $s)), 500);
    }
}
