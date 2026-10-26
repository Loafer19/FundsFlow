<?php

namespace App\Support\Admin;

use App\Enums\TransactionSource;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the /admin dashboard. Every query filters on an indexed timestamp
 * (users.created_at / last_active_at, transactions.created_at, tokens.last_used_at)
 * and results are cached for a few minutes, keyed by period.
 */
class DashboardMetrics
{
    public const PERIODS = [7, 30, 90];

    public const DEFAULT_PERIOD = 30;

    public const TTL = 300;

    private const TELEGRAM_EMAIL = '%@fundsflow.invalid';

    public static function normalizePeriod(mixed $period): int
    {
        $period = (int) $period;

        return in_array($period, self::PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * Day buckets (app timezone) for the last $days days, oldest first, today included.
     *
     * @return array<string, int> Y-m-d => 0
     */
    public static function emptyDays(int $days): array
    {
        $start = self::periodStart($days);
        $out = [];

        for ($i = 0; $i < $days; $i++) {
            $out[$start->addDays($i)->toDateString()] = 0;
        }

        return $out;
    }

    public static function periodStart(int $days): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfDay()->subDays($days - 1);
    }

    /**
     * @return array{
     *     users: int, blocked: int, telegram: int,
     *     signups: array<string, int>, signups_total: int, signups_previous: int,
     *     transactions: array<string, int>, transactions_total: int, transactions_previous: int,
     *     active: int, active_today: int, active_telegram: int,
     * }
     */
    public static function overview(int $days): array
    {
        return Cache::remember("admin:dash:overview:{$days}", self::TTL, function () use ($days): array {
            $start = self::periodStart($days);
            $previousStart = $start->subDays($days);

            $signups = self::perDay(DB::table('users'), 'created_at', $days);
            $transactions = self::perDay(DB::table('transactions'), 'created_at', $days);

            return [
                'users' => DB::table('users')->count(),
                'blocked' => DB::table('users')->whereNotNull('blocked_at')->count(),
                'telegram' => DB::table('identities')->where('provider', 'telegram')->distinct()->count('user_id'),
                'signups' => $signups,
                'signups_total' => array_sum($signups),
                'signups_previous' => DB::table('users')
                    ->where('created_at', '>=', $previousStart)->where('created_at', '<', $start)->count(),
                'transactions' => $transactions,
                'transactions_total' => array_sum($transactions),
                'transactions_previous' => DB::table('transactions')
                    ->where('created_at', '>=', $previousStart)->where('created_at', '<', $start)->count(),
                'active' => self::activeUsers($start),
                'active_today' => self::activeUsers(CarbonImmutable::now()->startOfDay()),
                'active_telegram' => self::activeTelegramUsers($start),
            ];
        });
    }

    /**
     * Transactions created per day, split by source.
     *
     * @return array<string, array<string, int>> source => [Y-m-d => count]
     */
    public static function transactionsBySource(int $days): array
    {
        return Cache::remember("admin:dash:tx_sources:{$days}", self::TTL, function () use ($days): array {
            $rows = DB::table('transactions')
                ->where('created_at', '>=', self::periodStart($days))
                ->selectRaw('DATE(created_at) as day, source, COUNT(*) as aggregate')
                ->groupBy('day', 'source')
                ->get();

            $out = [];

            foreach (TransactionSource::cases() as $source) {
                $out[$source->value] = self::emptyDays($days);
            }

            foreach ($rows as $row) {
                $source = (string) ($row->source ?? TransactionSource::Web->value);
                $out[$source] ??= self::emptyDays($days);

                if (array_key_exists((string) $row->day, $out[$source])) {
                    $out[$source][(string) $row->day] = (int) $row->aggregate;
                }
            }

            return $out;
        });
    }

    /**
     * @return array{telegram_only: int, web_only: int, both: int, neither: int, google: int, github: int, telegram: int, password: int}
     */
    public static function channels(): array
    {
        return Cache::remember('admin:dash:channels', self::TTL, function (): array {
            $hasProvider = fn (string $provider): string => "EXISTS (SELECT 1 FROM identities i WHERE i.user_id = users.id AND i.provider = '{$provider}')";
            $hasOauth = "EXISTS (SELECT 1 FROM identities i WHERE i.user_id = users.id AND i.provider IN ('google', 'github'))";
            // Web account = real email (Telegram-created accounts use a synthetic .invalid one) or Google/GitHub login.
            $web = "(users.email NOT LIKE '" . self::TELEGRAM_EMAIL . "' OR {$hasOauth})";
            $tg = $hasProvider('telegram');

            $row = DB::table('users')->selectRaw(
                "SUM(CASE WHEN {$tg} AND NOT {$web} THEN 1 ELSE 0 END) as telegram_only,
                 SUM(CASE WHEN {$web} AND NOT {$tg} THEN 1 ELSE 0 END) as web_only,
                 SUM(CASE WHEN {$tg} AND {$web} THEN 1 ELSE 0 END) as both_channels,
                 SUM(CASE WHEN NOT {$tg} AND NOT {$web} THEN 1 ELSE 0 END) as neither,
                 SUM(CASE WHEN {$hasProvider('google')} THEN 1 ELSE 0 END) as google,
                 SUM(CASE WHEN {$hasProvider('github')} THEN 1 ELSE 0 END) as github,
                 SUM(CASE WHEN {$tg} THEN 1 ELSE 0 END) as telegram,
                 SUM(CASE WHEN users.email NOT LIKE '" . self::TELEGRAM_EMAIL . "' AND NOT {$hasOauth} THEN 1 ELSE 0 END) as password"
            )->first();

            return [
                'telegram_only' => (int) ($row->telegram_only ?? 0),
                'web_only' => (int) ($row->web_only ?? 0),
                'both' => (int) ($row->both_channels ?? 0),
                'neither' => (int) ($row->neither ?? 0),
                'google' => (int) ($row->google ?? 0),
                'github' => (int) ($row->github ?? 0),
                'telegram' => (int) ($row->telegram ?? 0),
                'password' => (int) ($row->password ?? 0),
            ];
        });
    }

    /**
     * AI assists used today (sum of every user's own-day cache counter) vs today's capacity
     * (sum of per-user daily limits of non-blocked users; one-off bonuses not included).
     * Counters live only in cache, so there is no per-day history.
     *
     * @return array{used: int, users: int, capacity: int, enabled: bool}
     */
    public static function aiToday(): array
    {
        return Cache::remember('admin:dash:ai_today', 60, function (): array {
            $quota = app(TelegramAiQuota::class);
            $used = 0;
            $people = 0;

            User::query()->select(['id', 'preferences'])->chunkById(500, function ($chunk) use ($quota, &$used, &$people): void {
                $keys = $chunk->map(fn (User $u): string => $quota->key($u))->all();

                foreach (Cache::many($keys) as $value) {
                    if ((int) $value > 0) {
                        $used += (int) $value;
                        $people++;
                    }
                }
            });

            $enabled = $quota->enabledGlobally();
            $capacity = $enabled
                ? (int) DB::table('users')->whereNull('blocked_at')->selectRaw(
                    'COALESCE(SUM(CASE WHEN ai_daily_limit IS NULL THEN ? ELSE ai_daily_limit END), 0) as capacity',
                    [$quota->defaultLimit()],
                )->value('capacity')
                : 0;

            return ['used' => $used, 'users' => $people, 'capacity' => $capacity, 'enabled' => $enabled];
        });
    }

    public static function flush(): void
    {
        foreach (self::PERIODS as $days) {
            Cache::forget("admin:dash:overview:{$days}");
            Cache::forget("admin:dash:tx_sources:{$days}");
        }

        Cache::forget('admin:dash:channels');
        Cache::forget('admin:dash:ai_today');
    }

    /**
     * Distinct users seen since $since on any channel: web / Mini App / MCP (token use),
     * Telegram (users.last_active_at) or by adding a transaction (recurring runs excluded).
     */
    public static function activeUsers(CarbonImmutable $since): int
    {
        $ids = DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('last_used_at', '>=', $since)
            ->select('tokenable_id as uid')
            ->union(DB::table('users')->where('last_active_at', '>=', $since)->select('id as uid'))
            ->union(
                DB::table('transactions')
                    ->where('created_at', '>=', $since)
                    ->where('source', '!=', TransactionSource::Recurring->value)
                    ->select('user_id as uid')
            );

        return DB::query()->fromSub($ids, 'active_ids')->count();
    }

    /**
     * Change vs the previous period: [percent|null, direction].
     *
     * @return array{0: ?int, 1: 'down'|'flat'|'up'}
     */
    public static function trend(int $current, int $previous): array
    {
        if ($current === $previous) {
            return [0, 'flat'];
        }

        if ($previous === 0) {
            return [null, 'up'];
        }

        $pct = (int) round(($current - $previous) / $previous * 100);

        return [abs($pct), $current > $previous ? 'up' : 'down'];
    }

    private static function activeTelegramUsers(CarbonImmutable $since): int
    {
        $ids = DB::table('users')->where('last_active_at', '>=', $since)->select('id as uid')
            ->union(
                DB::table('transactions')
                    ->where('created_at', '>=', $since)
                    ->where('source', TransactionSource::Telegram->value)
                    ->select('user_id as uid')
            );

        return DB::query()->fromSub($ids, 'tg_ids')->count();
    }

    /**
     * @return array<string, int> Y-m-d => count
     */
    private static function perDay(Builder $query, string $column, int $days): array
    {
        $out = self::emptyDays($days);

        $rows = $query
            ->where($column, '>=', self::periodStart($days))
            ->selectRaw("DATE({$column}) as day, COUNT(*) as aggregate")
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        foreach ($rows as $day => $count) {
            if (array_key_exists((string) $day, $out)) {
                $out[(string) $day] = (int) $count;
            }
        }

        return $out;
    }
}
