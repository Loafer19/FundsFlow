<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activation funnel stamps on users, plus a small user_events log.
 * Writes are best-effort when a column has not been migrated yet.
 */
final class UserAnalytics
{
    public const METHODS = ['email', 'google', 'github', 'telegram'];

    public const ONBOARDING_STEPS = ['welcome', 'telegram', 'completed', 'dismissed'];

    /**
     * First-touch signup method and optional source (UTM/referrer or /start payload).
     * Never overwrites a method already stored.
     */
    public static function signedUp(User $user, string $method, ?string $source = null): void
    {
        if (!in_array($method, self::METHODS, true)) {
            return;
        }

        $source = self::sanitizeSource($source);

        self::patch($user, array_filter([
            'signup_method' => $method,
            'signup_source' => $source,
        ], fn ($value) => $value !== null), onlyIfNull: ['signup_method', 'signup_source']);
    }

    public static function telegramLinked(User $user): void
    {
        self::patch($user, ['telegram_linked_at' => now()], onlyIfNull: ['telegram_linked_at']);
    }

    public static function firstTransaction(User $user): void
    {
        self::patch($user, ['first_transaction_at' => now()], onlyIfNull: ['first_transaction_at']);
    }

    public static function onboardingStep(User $user, string $step): void
    {
        if (!in_array($step, self::ONBOARDING_STEPS, true)) {
            return;
        }

        $values = ['onboarding_step' => $step];
        $onlyIfNull = [];

        if (in_array($step, ['completed', 'dismissed'], true) && $user->onboarding_completed_at === null) {
            $values['onboarding_completed_at'] = now();
            $onlyIfNull[] = 'onboarding_completed_at';
        }

        self::patch($user, $values, onlyIfNull: $onlyIfNull);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function event(?User $user, string $name, array $meta = []): void
    {
        if (!Schema::hasTable('user_events')) {
            return;
        }

        UserEvent::create([
            'user_id' => $user?->getKey(),
            'name' => mb_substr($name, 0, 64),
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * Strip control characters, reject one-time Telegram link/login codes, cap length.
     */
    public static function sanitizeSource(?string $source): ?string
    {
        if ($source === null) {
            return null;
        }

        $source = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $source) ?? '');

        if ($source === '') {
            return null;
        }

        if (TelegramCode::normalize($source) !== null) {
            return null;
        }

        return mb_substr($source, 0, 255);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $onlyIfNull
     */
    private static function patch(User $user, array $values, array $onlyIfNull = []): void
    {
        if ($values === [] || !Schema::hasColumn('users', (string) array_key_first($values))) {
            return;
        }

        $query = DB::table('users')->where('id', $user->getKey());

        foreach ($onlyIfNull as $column) {
            if (array_key_exists($column, $values)) {
                $query->whereNull($column);
            }
        }

        // Query builder so updated_at stays as it is (same idea as UserActivity).
        $updated = $query->update($values);

        if ($updated === 0 && $onlyIfNull !== []) {
            return;
        }

        foreach ($values as $column => $value) {
            if (in_array($column, $onlyIfNull, true) && $user->getAttribute($column) !== null) {
                continue;
            }

            $user->setAttribute($column, $value);
        }
    }
}
