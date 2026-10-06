<?php

// Account management (admins only). Keep the keys identical to lang/en/users.php.
return [
    'title' => '帳號管理',
    'create' => '新增帳號',
    'edit' => '編輯帳號',
    'intro' => '每個帳號有自己的追蹤清單；同一篇貼文只抓取一次，所有追蹤它的帳號共用資料。',

    'cols' => [
        'email' => 'Email',
        'name' => '名稱',
        'admin' => '管理員',
        'status' => '狀態',
        'tracked' => '追蹤中貼文數',
        'created' => '建立時間',
        'actions' => '操作',
    ],

    'you' => '你',
    'yes' => '是',
    'active' => '啟用',
    'inactive' => '停用',
    'edit_link' => '編輯',
    'delete' => '刪除',
    'delete_confirm' => "確定要刪除帳號 :email 嗎？\n\n他的追蹤清單會一併移除；只有他一個人在追蹤的貼文會停止追蹤並刪除紀錄。此動作無法復原。",

    'form' => [
        'name' => '名稱',
        'email' => 'Email（登入用）',
        'password' => '密碼',
        'password_reset' => '重設密碼（留空則不變）',
        'hint' => '至少 :min 個字元。',
        'confirm' => '再輸入一次密碼',
        'admin' => '管理員（可以管理帳號）',
        'active' => '啟用（取消勾選＝停用，無法登入）',
        'create' => '建立帳號',
        'save' => '儲存',
        'cancel' => '取消',
    ],

    'flash' => [
        'created' => '已建立帳號 :email。',
        'updated' => '已更新帳號 :email。',
        'deleted' => '已刪除帳號 :email。',
    ],

    'guard' => [
        'last_admin' => '系統至少要保留一位啟用中的管理員。',
        'self_demote' => '不能停用自己，也不能取消自己的管理員權限。',
        'self_delete' => '不能刪除自己的帳號。',
    ],
];
