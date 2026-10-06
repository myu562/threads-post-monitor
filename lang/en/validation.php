<?php

// Only the rules and fields this app uses; everything else falls back to the framework's English.
// Keep the keys identical to lang/zh_TW/validation.php.
return [
    'required' => 'Please enter the :attribute.',
    'string' => 'The :attribute is not in the right format.',
    'email' => 'The :attribute is not a valid email address.',
    'min' => [
        'string' => 'The :attribute must be at least :min characters.',
    ],
    'max' => [
        'string' => 'The :attribute is too long.',
    ],
    'confirmed' => 'The two :attribute entries do not match.',
    'unique' => 'This :attribute is already in use.',
    'current_password' => 'The current password is not correct.',

    'custom' => [
        'email' => [
            'required' => 'Please enter the email address.',
            'email' => 'The email address is not valid.',
            'max' => 'The email address is too long.',
            'unique' => 'There is already an account with this email address.',
        ],
        'url' => [
            'required' => 'Please paste a Threads post URL.',
            'string' => 'Please paste a Threads post URL.',
            'max' => 'The URL is too long.',
        ],
    ],

    'attributes' => [
        'name' => 'name',
        'email' => 'email address',
        'password' => 'password',
        'new_password' => 'new password',
        'current_password' => 'current password',
        'url' => 'URL',
    ],
];
