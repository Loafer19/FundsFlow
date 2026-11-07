<?php

// Support requests ("Feedback / Report a problem" in the web app, /support in the bot).

return [
    // Extra Telegram chat id(s) that get new-request / user-reply alerts, comma-separated.
    // Admins (users.is_admin) with a linked Telegram always get them; set this when the
    // inbox should be a group chat or an account that isn't an admin.
    'admin_telegram_chat_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SUPPORT_ADMIN_TELEGRAM_CHAT_ID', '')),
    ))),

    // New requests per user per hour (web + bot + assistant together).
    'max_per_hour' => (int) env('SUPPORT_MAX_PER_HOUR', 5),
];
