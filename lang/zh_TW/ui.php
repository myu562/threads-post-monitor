<?php

// Interface strings shared by every page: header, menus, switches, login, password, error pages.
// Keep the keys identical to lang/en/ui.php (tests/Feature/I18nTest checks).
return [
    'tagline' => 'Threads 貼文成效追蹤',

    'nav' => [
        'main' => '主選單',
        'posts' => '追蹤清單',
        'password' => '修改密碼',
        'users' => '帳號管理',
        'logout' => '登出',
        'brand_home' => ':app：回追蹤清單',
    ],

    'theme' => [
        'label' => '暗色模式',
        'title' => '切換亮暗',
        'to_light' => '切換為亮色',
        'to_dark' => '切換為暗色',
    ],

    // The switch shows the language it switches TO.
    'locale' => [
        'toggle' => 'EN',
        'toggle_label' => 'EN（切換為英文介面）',
    ],

    'auth' => [
        'title' => '登入',
        'email' => 'Email',
        'password' => '密碼',
        'remember' => '記住我',
        'submit' => '登入',
    ],

    'password' => [
        'title' => '修改密碼',
        'current' => '目前密碼',
        'new' => '新密碼',
        'hint' => '至少 :min 個字元。',
        'confirm' => '再輸入一次新密碼',
        'submit' => '更新密碼',
        'updated' => '密碼已更新。',
    ],

    // PHP date() formats.
    'formats' => [
        'hour' => 'Y-m-d H時',
    ],

    'errors' => [
        'home' => '回首頁',
        '403' => ['title' => '沒有權限', 'message' => '你沒有權限開啟這個頁面。'],
        '404' => ['title' => '找不到頁面', 'message' => '這個頁面不存在，或你沒有權限查看。'],
        '419' => ['title' => '頁面已過期', 'message' => '表單已過期，請重新整理頁面後再試一次。'],
        '429' => ['title' => '嘗試次數過多', 'message' => '嘗試次數過多，請稍後再試。'],
    ],
];
