<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TrackedPostController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

// Language switch in the page header, for guests (login page) and signed-in users alike.
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

// No self-registration on purpose. Accounts are created by an admin at /users,
// or from the CLI with `php artisan threads:create-admin`.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login')->name('login.attempt');
});

// auth.session: a password change or admin reset signs out the account's other sessions.
Route::middleware(['auth', 'auth.session', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', [TrackedPostController::class, 'index'])->name('posts.index');
    Route::post('/posts', [TrackedPostController::class, 'store'])->name('posts.store');
    Route::get('/posts/{trackedPost}', [TrackedPostController::class, 'show'])->name('posts.show');
    Route::delete('/posts/{trackedPost}', [TrackedPostController::class, 'destroy'])->name('posts.destroy');
    Route::put('/posts/{trackedPost}/note', [TrackedPostController::class, 'updateNote'])->name('posts.note.update');
    Route::put('/posts/{trackedPost}/tags', [TagController::class, 'update'])->name('posts.tags.update');
    // Group, tags and note in one save (the edit area on each card and at the top of the post page).
    Route::put('/posts/{trackedPost}/meta', [TrackedPostController::class, 'updateMeta'])->name('posts.meta.update');

    // The user's own groups; another account's group is a 404.
    Route::post('/groups', [GroupController::class, 'store'])->name('groups.store');
    Route::put('/groups/{group}', [GroupController::class, 'update'])->name('groups.update');
    Route::delete('/groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');
    Route::post('/groups/{group}/move', [GroupController::class, 'move'])->name('groups.move');

    Route::get('/account/password', [PasswordController::class, 'edit'])->name('account.password');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('account.password.update');

    Route::middleware('admin')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserManagementController::class, 'create'])->name('users.create');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');
    });
});
