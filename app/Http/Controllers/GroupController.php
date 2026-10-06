<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\User;
use App\Support\InvalidInput;
use App\Support\UserMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The current user's own groups (contract G3): add, rename, delete, reorder.
 *
 * Every action only ever touches the signed-in user's groups; another account's group is a 404.
 * After each action the user goes back to the list they came from (the form carries it in
 * "redirect", same-site paths only), so search and filter parameters are kept.
 */
class GroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        try {
            $name = Group::normalizeName($request->input('name'));
            if (Group::findByName($user, $name) !== null) {
                throw new InvalidInput('posts.groups.name_taken', ['name' => $name]);
            }
            if (Group::where('user_id', $user->id)->count() >= Group::MAX_PER_USER) {
                throw new InvalidInput('posts.groups.too_many', ['max' => Group::MAX_PER_USER]);
            }
        } catch (InvalidInput $e) {
            return $this->backWithError($request, 'new', $e);
        }

        try {
            DB::transaction(fn () => self::create($user, $name));
        } catch (UniqueConstraintViolationException) {
            // The database's collation found a name ours did not (or another request just added it).
            return $this->backWithError($request, 'new', new InvalidInput('posts.groups.name_taken', ['name' => $name]));
        }

        return $this->back($request)->with('status', __('posts.flash.group_created', ['name' => $name]));
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $user = $request->user();
        Group::ownedOrFail($user, $group);

        try {
            $name = Group::normalizeName($request->input('name'));
            if (Group::findByName($user, $name, $group->id) !== null) {
                throw new InvalidInput('posts.groups.name_taken', ['name' => $name]);
            }
        } catch (InvalidInput $e) {
            return $this->backWithError($request, (string) $group->id, $e);
        }

        $group->name = $name;
        try {
            DB::transaction(fn () => $group->save());
        } catch (UniqueConstraintViolationException) {
            return $this->backWithError($request, (string) $group->id, new InvalidInput('posts.groups.name_taken', ['name' => $name]));
        }

        return $this->back($request)->with('status', __('posts.flash.group_renamed', ['name' => $name]));
    }

    public function destroy(Request $request, Group $group): RedirectResponse
    {
        Group::ownedOrFail($request->user(), $group);

        DB::transaction(function () use ($group) {
            // The foreign key also does this (nullOnDelete); done explicitly so it never depends on
            // the database enforcing foreign keys. Only the grouping goes: posts, tags and notes stay.
            $group->entries()->update(['group_id' => null]);
            $group->delete();
        });

        return $this->back($request)->with('status', __('posts.flash.group_deleted', ['name' => $group->name]));
    }

    public function move(Request $request, Group $group): RedirectResponse
    {
        $user = $request->user();
        Group::ownedOrFail($user, $group);

        $direction = $request->input('direction');
        if (! in_array($direction, ['up', 'down'], true)) {
            abort(422);
        }

        DB::transaction(function () use ($user, $group, $direction) {
            // Renumber first (1..n in display order) so ties and gaps never block a swap.
            $ids = $user->groups()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $pos = array_search((int) $group->id, $ids, true);
            $target = $direction === 'up' ? $pos - 1 : $pos + 1;
            if ($pos !== false && isset($ids[$target])) {
                [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];
            }
            foreach ($ids as $i => $id) {
                Group::whereKey($id)->where('user_id', $user->id)->update(['sort_order' => $i + 1]);
            }
        });

        return $this->back($request)->with('status', __('posts.flash.group_moved'));
    }

    /** Create a group at the end of the user's order. The caller has checked the name. */
    public static function create(User $user, string $name): Group
    {
        // Unordered query: an aggregate next to ORDER BY is rejected under MySQL's ONLY_FULL_GROUP_BY.
        $next = (int) Group::where('user_id', $user->id)->max('sort_order') + 1;

        return $user->groups()->create(['name' => $name, 'sort_order' => $next]);
    }

    /**
     * The user's group named $name, created at the end of the order when there is none. When the
     * unique index rejects the insert (the database's collation equates the name with an existing
     * one, or another request just created it), that existing group is used instead. Call inside a
     * transaction; the failed insert only rolls back its own savepoint.
     *
     * @throws InvalidInput when the database rejected the name but no matching group can be found
     */
    public static function createOrReuse(User $user, string $name): Group
    {
        if (($existing = Group::findByName($user, $name)) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => self::create($user, $name));
        } catch (UniqueConstraintViolationException) {
            return Group::findExisting($user, $name)
                ?? throw new InvalidInput('posts.groups.name_taken', ['name' => $name]);
        }
    }

    private function back(Request $request): RedirectResponse
    {
        return redirect()->to(LocaleController::safeRedirect($request->input('redirect', route('posts.index', [], false))));
    }

    /** Back to the list with the group manager open and the error next to the form that caused it. */
    private function backWithError(Request $request, string $form, InvalidInput $e): RedirectResponse
    {
        $name = $request->input('name');

        return $this->back($request)
            ->withErrors(['name' => UserMessage::of($e)], 'groups')
            ->withInput(['name' => is_string($name) ? mb_substr($name, 0, 200, 'UTF-8') : ''])
            ->with('group_form', $form);
    }
}
