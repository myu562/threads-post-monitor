<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Support\InvalidInput;
use App\Support\TextKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A label one user puts on posts in their own list. Tags are private to their user: they hang off
 * tracked_post_user rows (that user's entries), never off the shared tracked_posts row.
 *
 * Names are matched case- and accent-insensitively (TextKey, close to MySQL's utf8mb4_unicode_ci)
 * but keep the spelling they were first typed with.
 */
class Tag extends Model
{
    public const MAX_LENGTH = 30;

    public const MAX_PER_POST = 20;

    protected $fillable = ['name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The user's list entries carrying this tag. */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(TrackedPostUser::class, 'tag_tracked_post_user', 'tag_id', 'tracked_post_user_id');
    }

    /** Case- and accent-insensitive comparison key for a (normalised) name. */
    public static function key(string $name): string
    {
        return TextKey::fold($name);
    }

    /**
     * Normalise one name: trim, collapse inner whitespace, drop leading '#' (hashtag habit).
     * Returns null for an empty result.
     *
     * @throws InvalidInput when the name is not allowed (message: a translation key)
     */
    public static function normalizeName(string $raw): ?string
    {
        $name = trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', $raw));
        $name = trim(ltrim($name, "#\u{FF03}")); // ASCII and full-width number sign
        if ($name === '') {
            return null;
        }
        // Commas and number signs, ASCII and full-width.
        if (preg_match('/[,\x{FF0C}#\x{FF03}]/u', $name)) {
            throw new InvalidInput('posts.tags.no_comma', ['name' => $name]);
        }
        if (mb_strlen($name, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidInput('posts.tags.too_long', ['max' => self::MAX_LENGTH, 'name' => mb_substr($name, 0, self::MAX_LENGTH, 'UTF-8')]);
        }

        return $name;
    }

    /**
     * Parse a comma-separated input (ASCII, full-width or ideographic commas) into unique normalised names,
     * first spelling wins.
     *
     * @return list<string>
     *
     * @throws InvalidInput
     */
    public static function parseList(string $input): array
    {
        $names = [];
        // Separators: ASCII comma, full-width comma, ideographic comma.
        foreach (preg_split('/[,\x{FF0C}\x{3001}]/u', $input) ?: [] as $part) {
            $name = self::normalizeName($part);
            if ($name !== null && ! isset($names[self::key($name)])) {
                $names[self::key($name)] = $name;
            }
        }
        if (count($names) > self::MAX_PER_POST) {
            throw new InvalidInput('posts.tags.too_many', ['max' => self::MAX_PER_POST, 'count' => count($names)]);
        }

        return array_values($names);
    }

    /**
     * Replace the tag set of one of the user's entries with $names (already parsed), creating the
     * user's missing tags and deleting tags no entry uses any more. Call inside a transaction.
     *
     * @param  list<string>  $names
     */
    public static function syncForEntry(User $user, TrackedPostUser $entry, array $names): void
    {
        $existing = $user->tags()->get()->keyBy(fn (Tag $t) => self::key($t->name));
        $ids = [];
        foreach ($names as $name) {
            $tag = $existing->get(self::key($name)) ?? self::createOrReuse($user, $name);
            $ids[] = $tag->id;
        }
        $entry->tags()->sync($ids);
        self::pruneOrphans($user->id);
    }

    /**
     * Create the user's tag $name. When the unique index rejects it (the database's collation
     * equates it with an existing tag, or another request just created it), that tag is used.
     * The failed insert only rolls back its own savepoint.
     *
     * @throws InvalidInput when the database rejected the name but no matching tag can be found
     */
    public static function createOrReuse(User $user, string $name): self
    {
        try {
            return DB::transaction(fn () => $user->tags()->create(['name' => $name]));
        } catch (UniqueConstraintViolationException) {
            return $user->tags()->where('name', $name)->first()
                ?? $user->tags()->get()->first(fn (self $t) => self::key($t->name) === self::key($name))
                ?? throw new InvalidInput('posts.tags.name_conflict', ['name' => $name]);
        }
    }

    /** Delete this user's tags that no post in their list uses any more. */
    public static function pruneOrphans(int $userId): int
    {
        return static::query()->where('user_id', $userId)->whereDoesntHave('entries')->delete();
    }
}
