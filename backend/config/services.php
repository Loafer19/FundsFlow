<?php

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://127.0.0.1:3000'),
    ],

    'xai' => [
        'api_key' => env('XAI_API_KEY'),
        'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
        // Stable pinned vision model for OpenAI-compatible chat completions + image.
        'vision_model' => env('XAI_VISION_MODEL', 'grok-2-vision-1212'),
        // Text model for Telegram natural-language intents (shared daily AI budget).
        'text_model' => env('XAI_TEXT_MODEL', 'grok-2-1212'),
    ],

    // Shared daily AI budget for Telegram receipt vision + text intents.
    'ai_receipt' => [
        'daily_limit' => (int) env('AI_RECEIPT_DAILY_LIMIT', 5),
    ],
];
