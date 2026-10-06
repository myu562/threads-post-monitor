<?php

// Only the rules and fields this app uses; everything else falls back to the framework's English.
// Keep the keys identical to lang/en/validation.php.
return [
    'required' => '請輸入:attribute。',
    'string' => ':attribute格式不正確。',
    'email' => ':attribute格式不正確。',
    'min' => [
        'string' => ':attribute至少要 :min 個字元。',
    ],
    'max' => [
        'string' => ':attribute太長了。',
    ],
    'confirmed' => '兩次輸入的:attribute不一致。',
    'unique' => '這個:attribute已經被使用了。',
    'current_password' => '目前密碼不正確。',

    // Field names that read better with a space in Chinese typography get their own messages.
    'custom' => [
        'email' => [
            'required' => '請輸入 Email。',
            'email' => 'Email 格式不正確。',
            'max' => 'Email 太長了。',
            'unique' => '這個 Email 已經有帳號了。',
        ],
        'url' => [
            'required' => '請貼上 Threads 貼文網址。',
            'string' => '請貼上 Threads 貼文網址。',
            'max' => '網址太長了。',
        ],
    ],

    'attributes' => [
        'name' => '名稱',
        'email' => 'Email',
        'password' => '密碼',
        'new_password' => '新密碼',
        'current_password' => '目前密碼',
        'url' => '網址',
    ],
];
