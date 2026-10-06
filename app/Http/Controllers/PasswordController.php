<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('account.password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ], [], [
            // Messages come from lang/…/validation.php; here "password" is the new one.
            'password' => __('validation.attributes.new_password'),
        ]);

        $user = $request->user();
        $user->password = $data['password']; // hashed by the model cast
        $user->save();

        // Sign out every other session (and remember-me cookie) of this account; this one stays.
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        return redirect()->route('account.password')->with('status', __('ui.password.updated'));
    }
}
