<?php

// Telegram bot tuning knobs. Defaults mirror the previously hardcoded values.
// Credentials (bot token, webhook secret, xAI key) stay in config/services.php.

return [
    // Conversational session (pending agent action + summary) lifetime, minutes.
    'session_ttl_minutes' => (int) env('TELEGRAM_SESSION_TTL_MINUTES', 20),

    // Pending media / receipt draft lifetime, minutes.
    'pending_ttl_minutes' => (int) env('TELEGRAM_PENDING_TTL_MINUTES', 15),

    // Album (media group) collection.
    'album' => [
        'debounce_seconds' => (int) env('TELEGRAM_ALBUM_DEBOUNCE_SECONDS', 3),
        'collect_ttl_seconds' => (int) env('TELEGRAM_ALBUM_COLLECT_TTL_SECONDS', 60),
    ],

    // Max tool-calling rounds per agent turn.
    'agent_max_rounds' => (int) env('TELEGRAM_AGENT_MAX_ROUNDS', 6),

    // Stream assistant answers as a live draft (Bot API 9.5 sendMessageDraft, private chats);
    // the formatted answer is sent when it's done. Set false to show only "typing…" until then.
    'stream_drafts' => (bool) env('TELEGRAM_STREAM_DRAFTS', true),

    // Soft "few AI requests left" hint shows when remaining <= this.
    'low_quota_threshold' => (int) env('TELEGRAM_LOW_QUOTA_THRESHOLD', 2),

    // HTTP timeouts, seconds.
    'timeouts' => [
        'xai_chat' => (int) env('TELEGRAM_XAI_CHAT_TIMEOUT', 60),
        'xai_vision' => (int) env('TELEGRAM_XAI_VISION_TIMEOUT', 45),
        'xai_stt' => (int) env('TELEGRAM_XAI_STT_TIMEOUT', 60),
        'gotenberg_screenshot' => (int) env('GOTENBERG_SCREENSHOT_TIMEOUT', 60),
        'gotenberg_pdf' => (int) env('GOTENBERG_PDF_TIMEOUT', 120),
        'gotenberg_connect' => (int) env('GOTENBERG_CONNECT_TIMEOUT', 10),
    ],
];
