<?php

namespace App\Filament\Widgets;

use App\Models\Identity;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class FundsFlowStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $today = now()->startOfDay();
        $weekAgo = now()->subDays(7);

        $users = User::query()->count();
        $newUsers = User::query()->where('created_at', '>=', $weekAgo)->count();
        $telegram = Identity::query()->where('provider', 'telegram')->distinct()->count('user_id');
        // Web / Mini App / MCP: Sanctum stamps last_used_at on every authenticated request.
        // Telegram-only chats don't use tokens, so they are not counted here.
        $active = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('last_used_at', '>=', $today)
            ->distinct()
            ->count('tokenable_id');
        $txToday = Transaction::query()->where('created_at', '>=', $today)->count();
        $txWeek = Transaction::query()->where('created_at', '>=', $weekAgo)->count();
        [$aiUsed, $aiUsers] = $this->aiToday();

        return [
            Stat::make('Users', number_format($users))
                ->description("+{$newUsers} in the last 7 days")
                ->icon('heroicon-o-users'),
            Stat::make('Active today', number_format($active))
                ->description('Web, Mini App or MCP requests since midnight')
                ->icon('heroicon-o-bolt'),
            Stat::make('Telegram linked', number_format($telegram))
                ->description($users > 0 ? round($telegram / $users * 100) . '% of users' : '—')
                ->icon('heroicon-o-paper-airplane'),
            Stat::make('Transactions today', number_format($txToday))
                ->description(number_format($txWeek) . ' added in the last 7 days')
                ->icon('heroicon-o-banknotes'),
            Stat::make('AI assists today', number_format($aiUsed))
                ->description($aiUsers . ' ' . ($aiUsers === 1 ? 'user' : 'users') . ' · each user\'s own day')
                ->icon('heroicon-o-sparkles'),
        ];
    }

    /**
     * Sum of today's AI counters (cache keys telegram_ai:{id}:{user's date}), cached for a minute.
     *
     * @return array{0: int, 1: int}
     */
    private function aiToday(): array
    {
        return Cache::remember('admin:ai_today', 60, function (): array {
            $quota = app(TelegramAiQuota::class);
            $sum = 0;
            $people = 0;

            User::query()->select(['id', 'preferences'])->chunkById(500, function ($chunk) use ($quota, &$sum, &$people): void {
                $keys = $chunk->map(fn (User $u): string => $quota->key($u))->all();

                foreach (Cache::many($keys) as $value) {
                    if ((int) $value > 0) {
                        $sum += (int) $value;
                        $people++;
                    }
                }
            });

            return [$sum, $people];
        });
    }
}
