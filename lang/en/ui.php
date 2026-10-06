<?php

// Interface strings shared by every page: header, menus, switches, login, password, error pages.
// Keep the keys identical to lang/zh_TW/ui.php (tests/Feature/I18nTest checks).
return [
    'tagline' => 'Threads post performance tracker',

    'nav' => [
        'main' => 'Main menu',
        'posts' => 'Tracked posts',
        'password' => 'Password',
        'users' => 'Accounts',
        'logout' => 'Log out',
        'brand_home' => ':app: back to tracked posts',
    ],

    'theme' => [
        'label' => 'Dark mode',
        'title' => 'Switch light / dark',
        'to_light' => 'Switch to light mode',
        'to_dark' => 'Switch to dark mode',
    ],

    // The switch shows the language it switches TO.
    'locale' => [
        'toggle' => '中文',
        'toggle_label' => '中文 (switch to Traditional Chinese)',
    ],

    'auth' => [
        'title' => 'Log in',
        'email' => 'Email',
        'password' => 'Password',
        'remember' => 'Remember me',
        'submit' => 'Log in',
    ],

    'password' => [
        'title' => 'Change password',
        'current' => 'Current password',
        'new' => 'New password',
        'hint' => 'At least :min characters.',
        'confirm' => 'Repeat the new password',
        'submit' => 'Update password',
        'updated' => 'Password updated.',
    ],

    // PHP date() formats.
    'formats' => [
        'hour' => 'Y-m-d H:00',
    ],

    'errors' => [
        'home' => 'Back to the start page',
        '403' => ['title' => 'Forbidden', 'message' => 'You do not have permission to open this page.'],
        '404' => ['title' => 'Page not found', 'message' => 'This page does not exist, or you do not have access to it.'],
        '419' => ['title' => 'Page expired', 'message' => 'The form has expired. Reload the page and try again.'],
        '429' => ['title' => 'Too many attempts', 'message' => 'Too many attempts. Please try again later.'],
    ],
];
