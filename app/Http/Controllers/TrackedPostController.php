<?php

namespace App\Http\Controllers;

use App\Http\Exceptions\MetaFieldError;
use App\Models\Group;
use App\Models\Tag;
use App\Models\TrackedPost;
use App\Models\TrackedPostUser;
use App\Support\InvalidInput;
use App\Support\UserMessage;
use App\Threads\Exceptions\ShareResolveException;
use App\Threads\ThreadsUrl;
use App\Threads\TrackPostService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

class TrackedPostController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $this->filters($request);

        // The user's own tags: for the filter menu, and to resolve ?tag= names case-insensitively.
        $userTags = $user->tags()->whereHas('entries')->orderBy('name')->get();
        $tagsByKey = $userTags->keyBy(fn (Tag $t) => Tag::key($t->name));
        $groups = $user->groups()->withCount('entries')->get();

        // Only the current user's own list (contract C5) — admins get no special view here either.
        $query = $user->trackedPosts()
            ->with([
                'latestSnapshot',
                // raw_json is not needed for the breakdown and can be large.
                'snapshots' => fn ($q) => $q->select(self::SNAPSHOT_COLUMNS)->orderBy('captured_at')->orderBy('id'),
            ])
            ->latest('tracked_posts.id');

        if ($filters['status'] !== null) {
            $query->where('tracked_posts.status', $filters['status']);
        }

        // ?group=<id> (one of the user's groups) or ?group=none (Ungrouped). Like an unknown tag,
        // an id that is not one of the user's groups gives an empty list.
        $unknownGroup = false;
        if ($filters['group'] === 'none') {
            $query->whereNull('tracked_post_user.group_id');
        } elseif ($filters['group'] !== null) {
            if ($groups->contains('id', $filters['group'])) {
                $query->where('tracked_post_user.group_id', $filters['group']);
            } else {
                $unknownGroup = true;
            }
        }

        $appliedTags = [];
        $unknownTag = false;
        foreach ($filters['tags'] as $name) {
            $tag = $tagsByKey->get(Tag::key($name));
            if ($tag === null) {
                $unknownTag = true;
                $appliedTags[] = $name;

                continue;
            }
            $appliedTags[] = $tag->name;
            // AND: every requested tag must be on the entry.
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('tag_tracked_post_user')
                ->whereColumn('tag_tracked_post_user.tracked_post_user_id', 'tracked_post_user.id')
                ->where('tag_tracked_post_user.tag_id', $tag->id));
        }

        if ($filters['q'] !== null) {
            $like = '%'.self::escapeLike(mb_strtolower($filters['q'], 'UTF-8')).'%';
            $query->where(function ($w) use ($like, $user) {
                foreach (['tracked_posts.username', 'tracked_posts.shortcode', 'tracked_posts.caption', 'tracked_post_user.note'] as $col) {
                    $w->orWhereRaw("LOWER({$col}) LIKE ? ESCAPE '!'", [$like]);
                }
                $w->orWhereExists(fn ($q) => $q->selectRaw('1')->from('tag_tracked_post_user')
                    ->join('tags', 'tags.id', '=', 'tag_tracked_post_user.tag_id')
                    ->whereColumn('tag_tracked_post_user.tracked_post_user_id', 'tracked_post_user.id')
                    ->where('tags.user_id', $user->id)
                    ->whereRaw("LOWER(tags.name) LIKE ? ESCAPE '!'", [$like]));
                // The name of the group the entry is in (the user's own groups only).
                $w->orWhereExists(fn ($q) => $q->selectRaw('1')->from('groups')
                    ->whereColumn('groups.id', 'tracked_post_user.group_id')
                    ->where('groups.user_id', $user->id)
                    ->whereRaw("LOWER(groups.name) LIKE ? ESCAPE '!'", [$like]));
            });
        }

        $posts = $unknownTag || $unknownGroup ? new EloquentCollection : $query->get();
        // Per-user tags hang off the pivot rows: one query for the whole page.
        (new EloquentCollection($posts->pluck('pivot')->all()))->load('tags');

        $filtered = $filters['q'] !== null || $filters['status'] !== null || $filters['tags'] !== [] || $filters['group'] !== null;

        return view('posts.index', [
            'posts' => $posts,
            'sections' => $this->sections($posts, $groups, $filtered),
            'groups' => $groups,
            'filters' => array_merge($filters, ['tags' => $appliedTags]),
            'filtered' => $filtered,
            'userTags' => $userTags,
        ]);
    }

    /**
     * The list in sections (contract G2): one per group in the user's order, then Ungrouped.
     * Unfiltered, every group is shown (empty ones with 0) and Ungrouped only when it has posts;
     * filtered, only sections with a match are shown, and they start open.
     *
     * @return list<array{key: string, group: ?Group, name: string, posts: \Illuminate\Support\Collection, open: bool}>
     */
    private function sections(EloquentCollection $posts, EloquentCollection $groups, bool $filtered): array
    {
        $byGroup = $posts->groupBy(fn (TrackedPost $p) => $p->pivot->group_id === null ? 'none' : 'g'.(int) $p->pivot->group_id);

        $sections = [];
        foreach ($groups as $group) {
            $items = $byGroup->get('g'.$group->id, collect());
            if ($filtered && $items->isEmpty()) {
                continue;
            }
            $sections[] = ['key' => 'g'.$group->id, 'group' => $group, 'name' => $group->name, 'posts' => $items, 'open' => $filtered];
        }
        $ungrouped = $byGroup->get('none', collect());
        if ($ungrouped->isNotEmpty()) {
            $sections[] = ['key' => 'none', 'group' => null, 'name' => __('posts.groups.ungrouped'), 'posts' => $ungrouped, 'open' => $filtered];
        }

        return $sections;
    }

    /** Columns loaded for the per-snapshot breakdown (everything except raw_json). */
    private const SNAPSHOT_COLUMNS = [
        'id', 'tracked_post_id', 'captured_at', 'hours_since_publish',
        'views', 'likes', 'replies', 'reposts', 'quotes', 'shares',
    ];

    public const STATUS_FILTERS = ['pending', 'tracking', 'done', 'gone', 'failed'];

    /**
     * Search / filter parameters from the query string. Bad values are dropped, never an error:
     * the URLs are meant to be bookmarked and shared between tabs.
     *
     * @return array{q: ?string, tags: list<string>, status: ?string, group: int|'none'|null}
     */
    private function filters(Request $request): array
    {
        $q = $request->query('q');
        $q = is_string($q) ? trim((string) preg_replace('/\s+/u', ' ', $q)) : '';
        $q = $q === '' ? null : mb_substr($q, 0, 100, 'UTF-8');

        $raw = $request->query('tag', []);
        $raw = is_array($raw) ? $raw : [$raw];
        $tags = [];
        foreach ($raw as $t) {
            if (! is_string($t)) {
                continue;
            }
            try {
                $name = Tag::normalizeName($t);
            } catch (InvalidArgumentException) {
                $name = null;
            }
            if ($name !== null && ! isset($tags[Tag::key($name)])) {
                $tags[Tag::key($name)] = $name;
            }
        }

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, self::STATUS_FILTERS, true) ? $status : null;

        $group = $request->query('group');
        if ($group === 'none') {
            $group = 'none';
        } elseif (is_string($group) && preg_match('/^[1-9][0-9]{0,17}$/', $group)) {
            $group = (int) $group;
        } else {
            $group = null;
        }

        return ['q' => $q, 'tags' => array_slice(array_values($tags), 0, Tag::MAX_PER_POST), 'status' => $status, 'group' => $group];
    }

    /** Escape LIKE wildcards with '!' (a backslash escape is not portable across MySQL modes). */
    private static function escapeLike(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }

    public function store(Request $request, TrackPostService $service): RedirectResponse
    {
        $validator = Validator::make(
            $request->only('url'),
            ['url' => ['required', 'string', 'max:2048']],
        ); // messages: lang/…/validation.php, custom.url
        if ($validator->fails()) {
            return $this->backWithUrlError($request, $validator->errors()->first('url'));
        }
        $url = (string) $request->input('url');

        try {
            $service->register($url, $request->user());
        } catch (InvalidArgumentException|ShareResolveException $e) {
            return $this->backWithUrlError($request, UserMessage::of($e));
        }

        // Deliberately neutral: never reveal whether some other account already tracks this post.
        $message = $service->lastAttached ? __('posts.flash.added') : __('posts.flash.already');

        return redirect()->route('posts.index')->with('status', $message);
    }

    public function show(Request $request, TrackedPost $trackedPost): View
    {
        $entry = TrackedPostUser::forUserOrFail($request->user(), $trackedPost);
        $entry->load('tags', 'group');
        $trackedPost->load('latestSnapshot');
        $snapshots = $trackedPost->snapshots()->orderBy('captured_at')->orderBy('id')->get();

        return view('posts.show', [
            'post' => $trackedPost,
            'entry' => $entry,
            'groups' => $request->user()->groups()->get(),
            'snapshots' => $snapshots,
            'chart' => $this->viewsChart($snapshots, (int) config('threads.track_hours')),
        ]);
    }

    /** The user's own note on a post in their list (contract K3). */
    public function updateNote(Request $request, TrackedPost $trackedPost): RedirectResponse
    {
        $entry = TrackedPostUser::forUserOrFail($request->user(), $trackedPost);

        $raw = $request->input('note');
        if ($raw !== null && ! is_string($raw)) {
            return back()->withErrors(['note' => __('posts.note.invalid')], 'note');
        }
        // Count characters after normalising line breaks (a textarea sends CRLF).
        $note = trim(str_replace("\r\n", "\n", (string) $raw));
        if (mb_strlen($note, 'UTF-8') > TrackedPostUser::NOTE_MAX) {
            return back()->withErrors(['note' => __('posts.note.too_long', ['max' => TrackedPostUser::NOTE_MAX, 'count' => mb_strlen($note, 'UTF-8')])], 'note')
                ->withInput(['note' => $note]);
        }

        $entry->note = $note === '' ? null : $note;
        $entry->save();

        return redirect()->route('posts.show', $trackedPost)->with('status', __('posts.flash.note_saved'));
    }

    /**
     * Group, tags and note of one post in the user's own list, saved together (contract G4).
     *
     * group: "" or "none" = Ungrouped, "new" = create the group named in new_group (or use the
     * user's existing group of that name), otherwise the id of one of the user's own groups; any
     * other id is rejected with 422 and nothing changes. A field that is not sent is left as it is.
     * Afterwards the user goes back to the page the form was on ("redirect", same-site paths only),
     * so the list's search and filters are kept.
     */
    public function updateMeta(Request $request, TrackedPost $trackedPost): RedirectResponse
    {
        $user = $request->user();
        $entry = TrackedPostUser::forUserOrFail($user, $trackedPost);
        $redirect = LocaleController::safeRedirect($request->input('redirect', route('posts.show', $trackedPost, false)));

        $errors = [];
        $setGroup = $request->has('group');
        $groupId = null;
        $newGroupName = null;
        if ($setGroup) {
            $raw = $request->input('group');
            if ($raw === null || $raw === '' || $raw === 'none') {
                $groupId = null;
            } elseif ($raw === 'new') {
                try {
                    $newGroupName = Group::normalizeName($request->input('new_group'));
                    if (Group::findByName($user, $newGroupName) === null
                        && Group::where('user_id', $user->id)->count() >= Group::MAX_PER_USER) {
                        throw new InvalidInput('posts.groups.too_many', ['max' => Group::MAX_PER_USER]);
                    }
                } catch (InvalidInput $e) {
                    $errors['new_group'] = UserMessage::of($e);
                }
            } elseif (is_string($raw) && preg_match('/^[1-9][0-9]{0,17}$/', $raw)
                && ($group = Group::where('user_id', $user->id)->whereKey((int) $raw)->first()) !== null) {
                $groupId = $group->id;
            } else {
                abort(422); // not one of the user's groups (only a forged form gets here)
            }
        }

        $setTags = $request->has('tags');
        $names = [];
        if ($setTags) {
            $raw = $request->input('tags');
            if ($raw !== null && ! is_string($raw)) {
                $errors['tags'] = __('posts.tags.invalid_format');
            } else {
                try {
                    $names = Tag::parseList((string) $raw);
                } catch (InvalidArgumentException $e) {
                    $errors['tags'] = UserMessage::of($e);
                }
            }
        }

        $setNote = $request->has('note');
        $note = null;
        if ($setNote) {
            try {
                $note = TrackedPostUser::normalizeNote($request->input('note'));
            } catch (InvalidInput $e) {
                $errors['note'] = UserMessage::of($e);
            }
        }

        if ($errors === []) {
            try {
                DB::transaction(function () use ($user, $entry, $setGroup, $groupId, $newGroupName, $setTags, $names, $setNote, $note) {
                    if ($newGroupName !== null) {
                        try {
                            $groupId = GroupController::createOrReuse($user, $newGroupName)->id;
                        } catch (InvalidInput $e) {
                            throw new MetaFieldError('new_group', $e);
                        }
                    }
                    if ($setTags) {
                        try {
                            Tag::syncForEntry($user, $entry, $names);
                        } catch (InvalidInput $e) {
                            throw new MetaFieldError('tags', $e);
                        }
                    }
                    if ($setGroup) {
                        $entry->group_id = $groupId;
                    }
                    if ($setNote) {
                        $entry->note = $note;
                    }
                    $entry->save();
                });
            } catch (MetaFieldError $e) {
                // Nothing was saved (the whole transaction rolled back).
                $errors[$e->field] = UserMessage::of($e->getPrevious());
            }
        }

        if ($errors !== []) {
            $old = [];
            foreach (['group' => 20, 'new_group' => 200, 'tags' => 2000, 'note' => TrackedPostUser::NOTE_MAX + 200] as $field => $max) {
                $value = $request->input($field);
                if (is_string($value)) {
                    $old[$field] = mb_substr($value, 0, $max, 'UTF-8');
                }
            }

            return redirect()->to($redirect)->withErrors($errors, 'meta')->withInput($old)->with('meta_post', $trackedPost->id);
        }

        return redirect()->to($redirect)->with('status', __('posts.flash.meta_saved'));
    }

    public function destroy(Request $request, TrackedPost $trackedPost, TrackPostService $service): RedirectResponse
    {
        $this->ensureOwned($request, $trackedPost);

        $service->untrack($trackedPost, $request->user());
        Tag::pruneOrphans($request->user()->id); // tags only used on this post go with it

        // Same message whether or not other accounts still track it.
        return redirect()->route('posts.index')->with('status', __('posts.flash.removed'));
    }

    /**
     * Back to the form with an error. The raw input is not flashed to the session as is: a /share/
     * link (which identifies who shared it) is dropped entirely, any other URL loses its query and
     * fragment (tracking parameters such as xmt / igshid).
     */
    private function backWithUrlError(Request $request, string $error): RedirectResponse
    {
        $url = $request->input('url');
        $old = [];
        $isShare = is_string($url) && (ThreadsUrl::parseShare($url) !== null || stripos($url, '/share/') !== false);
        if (is_string($url) && $url !== '' && ! $isShare) {
            $old['url'] = Str::limit((string) preg_replace('/[?#].*$/s', '', $url), 2048, '');
        }

        return back()->withErrors(['url' => $error])->withInput($old);
    }

    /** Non-owners (admins included) get a 404 so other users' lists are not even confirmed to exist. */
    private function ensureOwned(Request $request, TrackedPost $post): void
    {
        TrackedPostUser::forUserOrFail($request->user(), $post);
    }

    /**
     * Geometry for an inline SVG line chart of views vs hours since publish. Null points are skipped.
     *
     * @return array{w:int,h:int,pad:array{l:int,r:int,t:int,b:int},points:string,dots:array,xTicks:array,yTicks:array}|null
     */
    private function viewsChart($snapshots, int $trackHours): ?array
    {
        $pts = [];
        foreach ($snapshots as $s) {
            if ($s->views === null || $s->hours_since_publish === null) {
                continue;
            }
            $pts[] = [(float) $s->hours_since_publish, (int) $s->views];
        }
        if ($pts === []) {
            return null;
        }

        $w = 640;
        $h = 260;
        $pad = ['l' => 64, 'r' => 16, 't' => 12, 'b' => 32];
        $maxX = max($trackHours, (int) ceil(max(array_column($pts, 0))));
        $maxY = max(1, max(array_column($pts, 1)));
        $minX = min(0.0, min(array_column($pts, 0)));

        $sx = fn (float $x) => $pad['l'] + ($x - $minX) / max(1e-9, $maxX - $minX) * ($w - $pad['l'] - $pad['r']);
        $sy = fn (float $y) => $h - $pad['b'] - $y / $maxY * ($h - $pad['t'] - $pad['b']);

        $dots = [];
        foreach ($pts as [$x, $y]) {
            $dots[] = ['x' => round($sx($x), 1), 'y' => round($sy($y), 1), 'hour' => $x, 'views' => $y];
        }

        $xTicks = [];
        $step = $maxX > 48 ? 12 : 6;
        for ($t = 0; $t <= $maxX; $t += $step) {
            $xTicks[] = ['x' => round($sx($t), 1), 'label' => $t.'h'];
        }
        $yTicks = [];
        for ($i = 0; $i <= 4; $i++) {
            $v = $maxY * $i / 4;
            $yTicks[] = ['y' => round($sy($v), 1), 'label' => number_format($v)];
        }

        return [
            'w' => $w, 'h' => $h, 'pad' => $pad,
            'points' => implode(' ', array_map(fn ($d) => $d['x'].','.$d['y'], $dots)),
            'dots' => $dots, 'xTicks' => $xTicks, 'yTicks' => $yTicks,
        ];
    }
}
