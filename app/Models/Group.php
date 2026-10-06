<?php

namespace App\Models;

use App\Support\InvalidInput;
use App\Support\TextKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group one user sorts posts in their own list into. Like tags, groups are private to their user
 * and hang off tracked_post_user rows (tracked_post_user.group_id): a post is in at most one group
 * per account. Names are unique per user, compared case- and accent-insensitively (TextKey), as
 * MySQL's utf8mb4_unicode_ci does; where the two still disagree, the unique index decides.
 */
class Group extends Model
{
    public const NAME_MAX = 40;

    /** Upper bound on groups per account, so the list page and its forms stay manageable. */
    public const MAX_PER_USER = 100;

    protected $fillable = ['name', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The user's list entries in this group. */
    public function entries(): HasMany
    {
        return $this->hasMany(TrackedPostUser::class, 'group_id');
    }

    /** Case- and accent-insensitive comparison key for a (normalised) name. */
    public static function key(string $name): string
    {
        return TextKey::fold($name);
    }

    /**
     * Normalise a group name: trim and collapse inner whitespace (including the ideographic space).
     *
     * @throws InvalidInput when the name is empty or too long (message: a translation key)
     */
    public static function normalizeName(mixed $raw): string
    {
        if (! is_string($raw) && $raw !== null) {
            throw new InvalidInput('posts.groups.name_required');
        }
        $name = trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', (string) $raw));
        if ($name === '') {
            throw new InvalidInput('posts.groups.name_required');
        }
        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw new InvalidInput('posts.groups.name_too_long', ['max' => self::NAME_MAX, 'count' => mb_strlen($name, 'UTF-8')]);
        }

        return $name;
    }

    /** The user's group with this name (case-insensitive), if any. */
    public static function findByName(User $user, string $name, ?int $exceptId = null): ?self
    {
        return $user->groups()->get()
            ->first(fn (self $g) => $g->id !== $exceptId && self::key($g->name) === self::key($name));
    }

    /**
     * The user's group the database considers equal to $name: the database's own comparison
     * (its collation) first, then ours. Used after the unique index rejected a new name.
     */
    public static function findExisting(User $user, string $name, ?int $exceptId = null): ?self
    {
        $match = $user->groups()->where('name', $name)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();

        return $match ?? self::findByName($user, $name, $exceptId);
    }

    /** The user's own group, or a 404 (other accounts' groups are not even confirmed to exist). */
    public static function ownedOrFail(User $user, self $group): self
    {
        if ((int) $group->user_id !== (int) $user->id) {
            abort(404);
        }

        return $group;
    }
}
