<?php

// Account management (admins only). Keep the keys identical to lang/zh_TW/users.php.
return [
    'title' => 'Accounts',
    'create' => 'Add account',
    'edit' => 'Edit account',
    'intro' => 'Each account has its own list of tracked posts. A post is fetched only once, and every account that tracks it shares the data.',

    'cols' => [
        'email' => 'Email',
        'name' => 'Name',
        'admin' => 'Admin',
        'status' => 'Status',
        'tracked' => 'Tracked posts',
        'created' => 'Created',
        'actions' => 'Actions',
    ],

    'you' => 'you',
    'yes' => 'Yes',
    'active' => 'Active',
    'inactive' => 'Disabled',
    'edit_link' => 'Edit',
    'delete' => 'Delete',
    'delete_confirm' => "Delete the account :email?\n\nIts list of tracked posts is removed too; posts that only this account tracks stop being tracked and their history is deleted. This cannot be undone.",

    'form' => [
        'name' => 'Name',
        'email' => 'Email (used to log in)',
        'password' => 'Password',
        'password_reset' => 'New password (leave empty to keep the current one)',
        'hint' => 'At least :min characters.',
        'confirm' => 'Repeat the password',
        'admin' => 'Admin (can manage accounts)',
        'active' => 'Active (untick to disable: the account can no longer log in)',
        'create' => 'Create account',
        'save' => 'Save',
        'cancel' => 'Cancel',
    ],

    'flash' => [
        'created' => 'Account :email created.',
        'updated' => 'Account :email updated.',
        'deleted' => 'Account :email deleted.',
    ],

    'guard' => [
        'last_admin' => 'There must always be at least one active admin.',
        'self_demote' => 'You cannot disable your own account or remove your own admin rights.',
        'self_delete' => 'You cannot delete your own account.',
    ],
];
