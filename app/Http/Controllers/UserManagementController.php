<?php

namespace App\Http\Controllers;

use App\Http\Exceptions\AccountGuardViolation;
use App\Models\User;
use App\Services\AdminAccounts;
use App\Support\UserMessage;
use App\Threads\TrackPostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin-only management of website login accounts (routes are behind the `admin` middleware). */
class UserManagementController extends Controller
{
    /** Translation key (lang/…/users.php); validation messages come from lang/…/validation.php. */
    private const LAST_ADMIN = 'users.guard.last_admin';

    public function __construct(private readonly AdminAccounts $accounts) {}

    public function index(): View
    {
        $users = User::withCount('trackedPosts')->orderBy('id')->get();

        return view('users.index', ['users' => $users]);
    }

    public function create(): View
    {
        return view('users.create', ['user' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $user = new User;
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password'];
        $user->forceFill([
            'is_admin' => $request->boolean('is_admin'),
            'is_active' => $request->boolean('is_active'),
        ])->save();

        return redirect()->route('users.index')->with('status', __('users.flash.created', ['email' => $user->email]));
    }

    public function edit(User $user): View
    {
        return view('users.edit', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $isAdmin = $request->boolean('is_admin');
        $isActive = $request->boolean('is_active');
        $isSelf = $user->is($request->user());

        if ($isSelf && (! $isAdmin || ! $isActive)) {
            return back()->withInput()->withErrors(['guard' => __('users.guard.self_demote')]);
        }

        // Hash outside the transaction so the row locks are held as briefly as possible.
        $newHash = empty($data['password']) ? null : Hash::make($data['password']);

        try {
            $result = $this->guarded(function () use ($user, $data, $isAdmin, $isActive, $newHash) {
                $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($this->isActiveAdmin($target) && (! $isAdmin || ! $isActive) && ! $this->hasOtherActiveAdmin($target)) {
                    throw new AccountGuardViolation(self::LAST_ADMIN);
                }

                $wasActive = $target->is_active;
                $target->name = $data['name'];
                $target->email = $data['email'];
                if ($newHash !== null) {
                    $target->password = $newHash; // already hashed: the cast keeps it as is
                }
                $target->forceFill(['is_admin' => $isAdmin, 'is_active' => $isActive]);
                $revoke = $newHash !== null || ($wasActive && ! $isActive);
                $target->save();

                return [$target, $revoke];
            });
        } catch (AccountGuardViolation $e) {
            return back()->withInput()->withErrors(['guard' => UserMessage::key($e->getMessage())]);
        }

        [$target, $revoke] = $result;
        if ($revoke) {
            $this->revokeSessions($request, $target, $isSelf);
        }

        return redirect()->route('users.index')->with('status', __('users.flash.updated', ['email' => $target->email]));
    }

    public function destroy(Request $request, User $user, TrackPostService $service): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['guard' => __('users.guard.self_delete')]);
        }

        try {
            $email = $this->guarded(function () use ($user, $service) {
                $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($this->isActiveAdmin($target) && ! $this->hasOtherActiveAdmin($target)) {
                    throw new AccountGuardViolation(self::LAST_ADMIN);
                }
                $email = $target->email;
                $service->deleteUser($target);

                return $email;
            });
        } catch (AccountGuardViolation $e) {
            return back()->withErrors(['guard' => UserMessage::key($e->getMessage())]);
        }

        $this->deleteSessionRows($user->id);

        return redirect()->route('users.index')->with('status', __('users.flash.deleted', ['email' => $email]));
    }

    /**
     * Run an account change in one transaction that holds a lock on every active admin row (MySQL),
     * then recount active admins after the write and roll everything back if none is left, so two
     * admins demoting each other at the same moment cannot both succeed.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     *
     * @throws AccountGuardViolation
     */
    private function guarded(callable $write): mixed
    {
        return DB::transaction(function () use ($write) {
            User::where('is_admin', true)->where('is_active', true)->lockForUpdate()->pluck('id');

            $result = $write();

            if (! User::where('is_admin', true)->where('is_active', true)->exists()) {
                throw new AccountGuardViolation(self::LAST_ADMIN);
            }

            return $result;
        });
    }

    /**
     * After an admin resets someone's password or deactivates them: end all their sessions and
     * invalidate their remember-me cookies. When admins reset their own password, their current
     * session is kept (and re-pinned to the new hash so AuthenticateSession does not drop it).
     */
    private function revokeSessions(Request $request, User $target, bool $isSelf): void
    {
        if ($isSelf) {
            Auth::guard('web')->setUser($target);
            $this->deleteSessionRows($target->id, $request->session()->getId());

            return;
        }

        $target->setRememberToken(Str::random(60));
        $target->saveQuietly();
        $this->deleteSessionRows($target->id);
    }

    private function deleteSessionRows(int $userId, ?string $keepSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->when($keepSessionId !== null, fn ($q) => $q->where('id', '!=', $keepSessionId))
            ->delete();
    }

    private function normalizeEmail(Request $request): void
    {
        $request->merge(['email' => $this->accounts->normalizeEmail((string) $request->input('email'))]);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->is_admin && $user->is_active;
    }

    private function hasOtherActiveAdmin(User $user): bool
    {
        return User::where('is_admin', true)->where('is_active', true)->whereKeyNot($user->id)->exists();
    }
}
