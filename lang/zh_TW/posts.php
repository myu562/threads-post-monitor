<?php

// Tracked posts: list, detail page, filters, tags and notes.
// Keep the keys identical to lang/en/posts.php (tests/Feature/I18nTest checks).
return [
    'title' => '追蹤清單',

    'flash' => [
        'added' => '已加入追蹤。',
        'already' => '這篇貼文已經在你的清單裡。',
        'removed' => '已從你的清單移除。',
        'note_saved' => '備註已儲存。',
        'tags_updated' => '標籤已更新。',
        'tags_cleared' => '已清除標籤。',
        'meta_saved' => '已更新。',
        'group_created' => '已新增分組「:name」。',
        'group_renamed' => '分組已改名為「:name」。',
        'group_deleted' => '已刪除分組「:name」，裡面的貼文已回到未分類。',
        'group_moved' => '已調整分組順序。',
    ],

    'add' => [
        'label' => '貼上 Threads 貼文網址',
        'placeholder' => 'https://www.threads.com/@帳號/post/代碼 或 /share/ 分享連結',
        'hint' => '可直接貼「分享」複製的 /share/ 連結，系統會自動還原成貼文；只會儲存乾淨的貼文網址。',
        'submit' => '開始追蹤',
    ],

    'list' => [
        'found' => '找到 :count 篇符合條件的貼文。',
        'empty_filtered' => '沒有符合條件的貼文。可以換個關鍵字、拿掉部分標籤條件，或:link。',
        'empty' => '還沒有追蹤任何貼文。貼上網址開始追蹤。',
    ],

    'unknown_account' => '(未知帳號)',
    'open_post' => '開啟貼文 ↗',
    'unverified_account' => '未驗證',
    'unverified_account_title' => '取自你加入的連結；成功抓到貼文頁面後才會確認（或更正）',
    'back' => '← 回清單',

    'meta' => [
        'published' => '發布：:time',
        'estimated' => '推算',
        'estimated_title' => '由貼文代碼推算，可能與實際發布時間差幾秒',
        'age' => '已發文：:value',
        'next_poll' => '下次抓取：:time',
        'track_until' => '追蹤到：:time',
        'last_poll' => '最後抓取：:time',
        'captured' => '取自 :time',
        'exact' => '（:time）',
    ],

    // trans_choice: :count can be a decimal (2.5).
    'hours' => ':count 小時',

    'warn' => [
        'added_late' => '加入時已超過 :hours 小時，非 :hours 小時那一刻的數字',
        'final_off' => '最終數字取自 :time',
    ],

    'figures' => [
        'current' => '目前',
        'final_done' => ':hours 小時最終',
        'final_gone' => '刪除前最後數字',
        'final_failed' => '失敗前最後數字',
    ],

    'metrics' => [
        'views' => '觀看',
        'likes' => '讚',
        'replies' => '留言',
        'reposts' => '轉發',
        'quotes' => '引用',
        'shares' => '分享',
    ],
    'metric_hidden' => '作者隱藏數字',
    'metric_na' => '無法取得',

    'status' => [
        'pending' => '等待首次抓取',
        'tracking' => '追蹤中',
        'done' => '已完成',
        'gone' => '貼文已刪除或不公開',
        'failed' => '失敗',
    ],

    'error' => [
        'failure_reason' => '失敗原因',
        'last_error' => '最近一次錯誤',
        'line' => ':label：:error',
        'line_with_count' => ':label（連續失敗 :count 次）：:error',
    ],

    'delta' => [
        'unknown' => '無法計算增量',
        'up' => '增加',
        'down' => '減少',
        'flat' => '無變化',
        'per_hour' => '每小時 :value',
    ],

    'history' => [
        'summary' => '展開分時紀錄（:count 筆）',
        'captured_at' => '抓取時間',
        'since_post' => '發文後',
        'note' => '每格下方是和前一筆相比的增量；間隔至少半小時時另附換算的每小時增量。「—」表示前後有一筆沒有數字。',
    ],

    'show' => [
        'chart_title' => '觀看數走勢',
        'chart_label' => '觀看數對發文後小時折線圖',
        'chart_dot' => '發文後 :hour 小時：:views 次觀看',
        'chart_note' => '橫軸：發文後小時；縱軸：觀看數。無法取得觀看數的抓取點不畫。',
        'chart_empty' => '還沒有可畫的觀看數資料。',
        'log_title' => '抓取紀錄（:count 筆）',
        'log_empty' => '尚未抓取。',
        'hours_col' => '發文後小時',
        'interval' => '間隔 :hours 小時',
        'log_note' => '每格下方是和前一筆相比的增量（+ 增加、− 減少、±0 無變化、— 前後有一筆沒有數字）；間隔至少半小時時另附換算的每小時增量。',
        'caption_expand' => '展開全文（:count 字）',
        'caption_collapse' => '收合全文',
    ],

    'filters' => [
        'search' => '搜尋',
        'search_placeholder' => '帳號、貼文代碼、內文、備註或標籤',
        'status' => '狀態',
        'all' => '全部',
        'add_tag' => '加上標籤條件',
        'no_tag' => '（不加）',
        'apply' => '套用',
        'applied' => '目前條件：',
        'keyword' => '關鍵字「:q」',
        'status_chip' => '狀態：:status',
        'remove_tag' => '移除這個標籤條件',
        'remove' => '移除',
        'all_tags' => '（標籤需全部符合）',
        'clear' => '清除篩選',
        'group' => '分組',
        'all_groups' => '全部分組',
        'group_chip' => '分組：:group',
    ],

    'tags' => [
        'list_label' => '標籤',
        'section' => '我的標籤與備註',
        'private' => '只有你看得到；其他帳號追蹤同一篇貼文時有各自的標籤與備註。',
        'label' => '標籤（用逗號分隔，送出後整組取代）',
        'placeholder' => '例如：產品, 活動, 合作',
        'hint' => '每個標籤最多 :max 字，每篇最多 :per_post 個；不能包含逗號或 #。留空送出會清除這篇的標籤。',
        'save' => '儲存標籤',
        'invalid_format' => '標籤格式不正確，請用逗號分隔。',
        'no_comma' => '標籤「:name」不能包含逗號或 #。',
        'too_long' => '每個標籤最多 :max 個字：「:name…」太長了。',
        'too_many' => '每篇貼文最多 :max 個標籤（目前 :count 個）。',
        'name_conflict' => '標籤「:name」和你既有的標籤衝突，請改用既有標籤的寫法。',
    ],

    'note' => [
        'label' => '備註',
        'hint' => '最多 :max 字。留空送出會清除備註。',
        'save' => '儲存備註',
        'invalid' => '備註格式不正確。',
        'too_long' => '備註最多 :max 個字（目前 :count 字）。',
    ],

    'untrack' => [
        'button' => '從我的清單移除',
        'confirm' => "確定要從你的清單移除這篇貼文嗎？\n\n・只會從你的清單移除。\n・如果還有其他帳號在追蹤，他們的資料和抓取不受影響。\n・只有在沒有任何帳號追蹤時，這篇貼文的追蹤紀錄才會被刪除。",
    ],
    'groups' => [
        'ungrouped' => '未分類',
        'count' => ':count 篇',
        'empty_section' => '這個分組還沒有貼文。在貼文卡片按「編輯」就能把貼文放進來。',
        'sections_label' => '依分組顯示的貼文',
        'manage' => '管理分組',
        'manage_hint' => '分組只有你看得到。刪除分組時，裡面的貼文會回到「未分類」，貼文、標籤和備註都會保留。',
        'none_yet' => '還沒有分組。新增一個，或在貼文卡片的「編輯」裡直接建立。',
        'new_label' => '新增分組',
        'new_placeholder' => '分組名稱，例如：產品發表',
        'add' => '新增',
        'rename_label' => '「:name」的名稱',
        'rename' => '改名',
        'move_up' => '上移',
        'move_up_label' => '把「:name」上移',
        'move_down' => '下移',
        'move_down_label' => '把「:name」下移',
        'delete' => '刪除',
        'delete_label' => '刪除分組「:name」',
        'delete_confirm' => "確定要刪除分組「:name」嗎？\n\n裡面的 :count 篇貼文會回到「未分類」，貼文、標籤和備註都會保留。",
        'name_required' => '請輸入分組名稱。',
        'name_too_long' => '分組名稱最多 :max 個字（目前 :count 字）。',
        'name_taken' => '已經有名為「:name」的分組。',
        'too_many' => '最多只能建立 :max 個分組。',
    ],

    'edit' => [
        'summary' => '編輯',
        'section' => '分組、標籤與備註',
        'group' => '分組',
        'ungrouped_option' => '未分類',
        'new_option' => '新增分組…',
        'new_label' => '新分組名稱',
        'new_hint' => '選了「新增分組…」才會使用，儲存時一併建立。',
        'tags' => '標籤（用逗號分隔）',
        'note' => '備註',
        'save' => '儲存',
    ],
];
