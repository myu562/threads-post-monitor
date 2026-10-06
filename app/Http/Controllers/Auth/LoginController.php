<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AdminAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    /**
     * Unknown email, deactivated account and wrong password all fail with the same message and the
     * same work: exactly one password hash check each, inside Laravel's login Timebox (see
     * App\Auth\ThreadsUserProvider), so neither the reply nor its timing tells them apart.
     */
    public function login(Request $request, AdminAccounts $accounts): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $credentials['email'] = $accounts->normalizeEmail($credentials['email']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return $this->failed();
        }

        // The provider already rejects deactivated accounts; kept as a second line of defence.
        if (! Auth::user()->is_active) {
            Auth::logout();

            return $this->failed();
        }

        $request->session()->regenerate();

        return redirect()->intended(route('posts.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function failed(): RedirectResponse
    {
        return back()
            ->withErrors(['email' => __('auth.failed')])
            ->onlyInput('email');
    }
}
