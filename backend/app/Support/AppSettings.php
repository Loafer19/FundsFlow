<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * App-wide runtime settings (admin → Settings), stored as JSON values in `app_settings`
 * and cached as one array until the next save. A missing key (or a null value) falls back
 * to env/config, so a fresh install behaves exactly as before.
 */
final class AppSettings
{
    /** Global AI kill switch: off = every AI path (receipts, voice, assistant, documents) is refused. */
    public const AI_ENABLED = 'ai.enabled';

    /** Daily AI assists for users without their own limit; null = AI_RECEIPT_DAILY_LIMIT. */
    public const AI_DEFAULT_DAILY_LIMIT = 'ai.default_daily_limit';

    /** New accounts (email sign-up, Google/GitHub, Telegram /start). Existing users can still sign in. */
    public const REGISTRATION_ENABLED = 'registration.enabled';

    private const TABLE = 'app_settings';

    private const CACHE_KEY = 'app_settings:v1';

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn (): array => DB::table(self::TABLE)
                ->pluck('value', 'key')
                ->map(fn (?string $value): mixed => $value === null ? null : json_decode($value, true))
                ->all());
        } catch (QueryException) {
            // Table not migrated yet: behave as if nothing is overridden.
            return [];
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Save several settings at once; null removes the override (back to env default).
     *
     * @param array<string, mixed> $values
     */
    public function set(array $values): void
    {
        DB::transaction(function () use ($values): void {
            $now = now();

            foreach ($values as $key => $value) {
                if ($value === null) {
                    DB::table(self::TABLE)->where('key', $key)->delete();

                    continue;
                }

                DB::table(self::TABLE)->upsert(
                    [['key' => $key, 'value' => json_encode($value), 'created_at' => $now, 'updated_at' => $now]],
                    ['key'],
                    ['value', 'updated_at'],
                );
            }
        });

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function aiEnabled(): bool
    {
        return (bool) $this->get(self::AI_ENABLED, true);
    }

    public function aiDefaultDailyLimit(): ?int
    {
        $value = $this->get(self::AI_DEFAULT_DAILY_LIMIT);

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    public function registrationEnabled(): bool
    {
        return (bool) $this->get(self::REGISTRATION_ENABLED, true);
    }
}
