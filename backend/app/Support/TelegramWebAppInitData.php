<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class TelegramWebAppInitData
{
    public function __construct(
        private readonly int $maxAgeSeconds = 86400,
    ) {}

    /**
     * Validate Telegram WebApp initData and return parsed fields.
     *
     * @return array{
     *     user: array{
     *         id: int,
     *         username: ?string,
     *         first_name: ?string,
     *         last_name: ?string,
     *         language_code: ?string,
     *         photo_url: ?string
     *     },
     *     auth_date: int,
     *     raw: array<string, string>
     * }
     *
     * @throws ValidationException
     */
    public function parse(string $initData): array
    {
        $initData = trim($initData);

        if ($initData === '') {
            throw ValidationException::withMessages([
                'initData' => ['Telegram WebApp initData is required.'],
            ]);
        }

        $botToken = (string) config('services.telegram.bot_token');

        if ($botToken === '') {
            throw ValidationException::withMessages([
                'initData' => ['Telegram bot is not configured.'],
            ]);
        }

        parse_str($initData, $params);

        if (!is_array($params) || $params === []) {
            throw ValidationException::withMessages([
                'initData' => ['Telegram WebApp initData is invalid.'],
            ]);
        }

        /** @var array<string, string> $params */
        $params = array_map(static fn ($value) => is_scalar($value) ? (string) $value : '', $params);

        $hash = $params['hash'] ?? '';

        if ($hash === '') {
            throw ValidationException::withMessages([
                'initData' => ['Telegram WebApp initData hash is missing.'],
            ]);
        }

        unset($params['hash']);

        $dataCheckString = collect($params)
            ->sortKeys()
            ->map(static fn (string $value, string $key) => "{$key}={$value}")
            ->implode("\n");

        // Per Telegram docs: secret_key = HMAC_SHA256(key="WebAppData", message=bot_token)
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($calculatedHash, $hash)) {
            throw ValidationException::withMessages([
                'initData' => ['Telegram WebApp initData hash is invalid.'],
            ]);
        }

        $authDate = isset($params['auth_date']) ? (int) $params['auth_date'] : 0;

        if ($authDate <= 0) {
            throw ValidationException::withMessages([
                'initData' => ['Telegram WebApp initData auth_date is missing.'],
            ]);
        }

        if ((time() - $authDate) > $this->maxAgeSeconds) {
            throw ValidationException::withMessages([
                'initData' => ['Telegram WebApp initData has expired. Re-open the Mini App.'],
            ]);
        }

        $userJson = $params['user'] ?? '';

        if ($userJson === '') {
            throw ValidationException::withMessages([
                'initData' => ['Telegram WebApp initData user is missing.'],
            ]);
        }

        $user = json_decode($userJson, true);

        if (!is_array($user) || !isset($user['id'])) {
            throw ValidationException::withMessages([
                'initData' => ['Telegram WebApp initData user is invalid.'],
            ]);
        }

        return [
            'user' => [
                'id' => (int) $user['id'],
                'username' => isset($user['username']) ? (string) $user['username'] : null,
                'first_name' => isset($user['first_name']) ? (string) $user['first_name'] : null,
                'last_name' => isset($user['last_name']) ? (string) $user['last_name'] : null,
                'language_code' => isset($user['language_code']) ? (string) $user['language_code'] : null,
                'photo_url' => isset($user['photo_url']) ? (string) $user['photo_url'] : null,
            ],
            'auth_date' => $authDate,
            'raw' => $params,
        ];
    }

    /**
     * @param array{
     *     id: int,
     *     username: ?string,
     *     first_name: ?string,
     *     last_name: ?string,
     *     language_code: ?string,
     *     photo_url: ?string
     * } $telegramUser
     * @return array<string, mixed>
     */
    public function identityMeta(array $telegramUser): array
    {
        return array_filter([
            'username' => $telegramUser['username'],
            'first_name' => $telegramUser['first_name'],
            'last_name' => $telegramUser['last_name'],
            'language_code' => $telegramUser['language_code'],
            'photo_url' => $telegramUser['photo_url'],
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
