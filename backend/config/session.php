<?php

// Sessions exist only for the Filament admin panel (/admin): the API stays stateless
// (Sanctum bearer tokens, no session middleware on routes/api.php). Keys not listed here
// fall back to Laravel's defaults.
return [
    // Redis when the app cache is Redis (prod), else files (local / tests).
    'driver' => env('SESSION_DRIVER', env('CACHE_STORE') === 'redis' ? 'redis' : 'file'),

    // Panel sessions are short; Filament's "remember me" is off.
    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => (bool) env('SESSION_EXPIRE_ON_CLOSE', false),

    'encrypt' => (bool) env('SESSION_ENCRYPT', false),

    // Redis connection for the "redis" driver (config/database.php → redis.default).
    'connection' => env('SESSION_CONNECTION'),

    'cookie' => env('SESSION_COOKIE', 'fundsflow_admin_session'),

    // Only the panel (/admin, /livewire*) starts a session; '/' so both paths get the cookie.
    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    'secure' => (bool) env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),

    'http_only' => true,

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => false,
];
