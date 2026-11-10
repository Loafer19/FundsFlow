<?php

// Personal Monobank API (https://api.monobank.ua/docs/). The webhook URL is
// built from APP_URL, which must be the public API origin (https://api… ).

return [
    'base_url' => env('MONOBANK_BASE_URL', 'https://api.monobank.ua'),
    // client-info and statement: no more than once per 60 seconds each.
    // We share one limiter per connection so a connect + backfill cannot 429.
    'request_interval' => (int) env('MONOBANK_REQUEST_INTERVAL', 60),
    // 31 days + 1 hour, in seconds (official statement window).
    'statement_max_seconds' => 2682000,
    'uah_currency_code' => 980,
];
