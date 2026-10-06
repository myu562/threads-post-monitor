<?php

// Tracked posts: list, detail page, filters, tags and notes.
// Keep the keys identical to lang/zh_TW/posts.php (tests/Feature/I18nTest checks).
return [
    'title' => 'Tracked posts',

    'flash' => [
        'added' => 'Now tracking this post.',
        'already' => 'This post is already in your list.',
        'removed' => 'Removed from your list.',
        'note_saved' => 'Note saved.',
        'tags_updated' => 'Tags updated.',
        'tags_cleared' => 'Tags cleared.',
        'meta_saved' => 'Updated.',
        'group_created' => 'Group ":name" added.',
        'group_renamed' => 'Group renamed to ":name".',
        'group_deleted' => 'Group ":name" deleted; its posts are back in Ungrouped.',
        'group_moved' => 'Group order updated.',
    ],

    'add' => [
        'label' => 'Paste a Threads post URL',
        'placeholder' => 'https://www.threads.com/@username/post/CODE or a /share/ link',
        'hint' => 'A /share/ link copied with "Share" works too: it is turned back into the post, and only the clean post URL is stored.',
        'submit' => 'Track',
    ],

    'list' => [
        'found' => '{1} Found 1 matching post.|[0,*] Found :count matching posts.',
        'empty_filtered' => 'No posts match. Try another keyword, drop some tag filters, or :link.',
        'empty' => 'No posts tracked yet. Paste a URL above to start.',
    ],

    'unknown_account' => '(unknown account)',
    'open_post' => 'Open post ↗',
    'unverified_account' => 'unverified',
    'unverified_account_title' => 'Taken from the link you added; it is confirmed (or corrected) once the post page has been fetched',
    'back' => '← Back to the list',

    'meta' => [
        'published' => 'Published: :time',
        'estimated' => 'estimated',
        'estimated_title' => 'Estimated from the post code; may differ from the real time by a few seconds',
        'age' => 'Age: :value',
        'next_poll' => 'Next fetch: :time',
        'track_until' => 'Tracked until: :time',
        'last_poll' => 'Last fetch: :time',
        'captured' => 'as of :time',
        'exact' => '(:time)',
    ],

    // trans_choice: :count can be a decimal (2.5).
    'hours' => '{1} :count hour|[0,*] :count hours',

    'warn' => [
        'added_late' => 'Added after :hours hours: not the :hours-hour figures',
        'final_off' => 'Final figures as of :time',
    ],

    'figures' => [
        'current' => 'Current',
        'final_done' => ':hours-hour final',
        'final_gone' => 'Last figures before deletion',
        'final_failed' => 'Last figures before failing',
    ],

    'metrics' => [
        'views' => 'Views',
        'likes' => 'Likes',
        'replies' => 'Replies',
        'reposts' => 'Reposts',
        'quotes' => 'Quotes',
        'shares' => 'Shares',
    ],
    'metric_hidden' => 'Hidden by author',
    'metric_na' => 'Not available',

    'status' => [
        'pending' => 'Waiting for first fetch',
        'tracking' => 'Tracking',
        'done' => 'Done',
        'gone' => 'Deleted or not public',
        'failed' => 'Failed',
    ],

    'error' => [
        'failure_reason' => 'Reason for failure',
        'last_error' => 'Last error',
        'line' => ':label: :error',
        'line_with_count' => ':label (failed :count times in a row): :error',
    ],

    'delta' => [
        'unknown' => 'Change not available',
        'up' => 'Up',
        'down' => 'Down',
        'flat' => 'No change',
        'per_hour' => ':value per hour',
    ],

    'history' => [
        'summary' => '{1} Show hourly history (1 reading)|[0,*] Show hourly history (:count readings)',
        'captured_at' => 'Fetched at',
        'since_post' => 'Since post',
        'note' => 'Under each value: the change since the previous reading; when the readings are at least half an hour apart, also the change per hour. "—" means one of the two readings has no number.',
    ],

    'show' => [
        'chart_title' => 'Views over time',
        'chart_label' => 'Line chart of views by hours since posting',
        'chart_dot' => ':hour h after posting: :views views',
        'chart_note' => 'Across: hours since posting; up: views. Fetches without a view count are not drawn.',
        'chart_empty' => 'No view counts to chart yet.',
        'log_title' => '{1} Fetch log (1 reading)|[0,*] Fetch log (:count readings)',
        'log_empty' => 'Not fetched yet.',
        'hours_col' => 'Hours since post',
        'interval' => ':hours h apart',
        'log_note' => 'Under each value: the change since the previous reading (+ up, − down, ±0 no change, — one of the two readings has no number); when the readings are at least half an hour apart, also the change per hour.',
        'caption_expand' => 'Show full text (:count characters)',
        'caption_collapse' => 'Hide full text',
    ],

    'filters' => [
        'search' => 'Search',
        'search_placeholder' => 'Account, post code, text, note or tag',
        'status' => 'Status',
        'all' => 'All',
        'add_tag' => 'Add a tag filter',
        'no_tag' => '(none)',
        'apply' => 'Apply',
        'applied' => 'Filters:',
        'keyword' => 'Keyword “:q”',
        'status_chip' => 'Status: :status',
        'remove_tag' => 'Remove this tag filter',
        'remove' => 'Remove',
        'all_tags' => '(posts must have every tag)',
        'clear' => 'clear filters',
        'group' => 'Group',
        'all_groups' => 'All groups',
        'group_chip' => 'Group: :group',
    ],

    'tags' => [
        'list_label' => 'Tags',
        'section' => 'My tags and note',
        'private' => 'Only you can see these. Other accounts tracking the same post have their own tags and notes.',
        'label' => 'Tags (comma-separated; saving replaces the whole set)',
        'placeholder' => 'e.g. product, campaign, partner',
        'hint' => 'Up to :max characters per tag and :per_post tags per post; no commas or #. Saving an empty field clears this post\'s tags.',
        'save' => 'Save tags',
        'invalid_format' => 'Tags are not in the right format. Separate them with commas.',
        'no_comma' => 'The tag “:name” cannot contain a comma or #.',
        'too_long' => 'A tag can have at most :max characters: “:name…” is too long.',
        'too_many' => 'A post can have at most :max tags (this has :count).',
        'name_conflict' => 'The tag “:name” clashes with one of your existing tags. Use that tag\'s spelling instead.',
    ],

    'note' => [
        'label' => 'Note',
        'hint' => 'Up to :max characters. Saving an empty note deletes it.',
        'save' => 'Save note',
        'invalid' => 'The note is not in the right format.',
        'too_long' => 'A note can have at most :max characters (this has :count).',
    ],

    'untrack' => [
        'button' => 'Remove from my list',
        'confirm' => "Remove this post from your list?\n\n- It is only removed from your list.\n- Other accounts that track it keep their data, and fetching continues for them.\n- Only when no account tracks it any more is its tracking history deleted.",
    ],
    'groups' => [
        'ungrouped' => 'Ungrouped',
        'count' => '{1} 1 post|[0,*] :count posts',
        'empty_section' => 'No posts in this group yet. Use "Edit" on a post to move it here.',
        'sections_label' => 'Posts by group',
        'manage' => 'Manage groups',
        'manage_hint' => 'Only you see your groups. Deleting a group moves its posts back to Ungrouped; the posts, their tags and notes are kept.',
        'none_yet' => 'No groups yet. Add one here, or create one from "Edit" on a post.',
        'new_label' => 'New group',
        'new_placeholder' => 'Group name, e.g. Product launch',
        'add' => 'Add',
        'rename_label' => 'Name of ":name"',
        'rename' => 'Rename',
        'move_up' => 'Up',
        'move_up_label' => 'Move ":name" up',
        'move_down' => 'Down',
        'move_down_label' => 'Move ":name" down',
        'delete' => 'Delete',
        'delete_label' => 'Delete the group ":name"',
        'delete_confirm' => "Delete the group \":name\"?\n\nIts posts (:count) go back to Ungrouped; the posts, their tags and notes are kept.",
        'name_required' => 'Enter a group name.',
        'name_too_long' => 'A group name can be at most :max characters (this one has :count).',
        'name_taken' => 'You already have a group named ":name".',
        'too_many' => 'You can have at most :max groups.',
    ],

    'edit' => [
        'summary' => 'Edit',
        'section' => 'Group, tags and note',
        'group' => 'Group',
        'ungrouped_option' => 'Ungrouped',
        'new_option' => 'New group…',
        'new_label' => 'New group name',
        'new_hint' => 'Only used when "New group…" is selected; the group is created when you save.',
        'tags' => 'Tags (comma-separated)',
        'note' => 'Note',
        'save' => 'Save',
    ],
];
