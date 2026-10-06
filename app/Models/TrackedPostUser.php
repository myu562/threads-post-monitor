<?php

namespace App\Models;

use App\Support\InvalidInput;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One user's entry for one tracked post (a row of tracked_post_user). Per-user data lives here:
 * the note, the tags and the group. Fetch data stays shared on tracked_posts.
 */
class TrackedPostUser extends Pivot
{
    public const NOTE_MAX = 1000;

    protected $table = 'tracked_post_user';

    public $incrementing = true;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trackedPost(): BelongsTo
    {
        return $this->belongsTo(TrackedPost::class);
    }

    /** The user's group this entry is in; null means Ungrouped. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_tracked_post_user', 'tracked_post_user_id', 'tag_id')
            ->orderBy('tags.name');
    }

    /**
     * Normalise a note from a form: CRLF to LF, trimmed; an empty note is null.
     *
     * @throws InvalidInput when it is not a string or is too long
     */
    public static function normalizeNote(mixed $raw): ?string
    {
        if ($raw !== null && ! is_string($raw)) {
            throw new InvalidInput('posts.note.invalid');
        }
        // Count characters after normalising line breaks (a textarea sends CRLF).
        $note = trim(str_replace("\r\n", "\n", (string) $raw));
        $count = mb_strlen($note, 'UTF-8');
        if ($count > self::NOTE_MAX) {
            throw new InvalidInput('posts.note.too_long', ['max' => self::NOTE_MAX, 'count' => $count]);
        }

        return $note === '' ? null : $note;
    }

    /** The user's own entry for the post, or a 404 (others' lists are not even confirmed to exist). */
    public static function forUserOrFail(User $user, TrackedPost $post): self
    {
        $entry = static::query()->where('user_id', $user->id)->where('tracked_post_id', $post->id)->first();
        if ($entry === null) {
            abort(404);
        }

        return $entry;
    }
}
