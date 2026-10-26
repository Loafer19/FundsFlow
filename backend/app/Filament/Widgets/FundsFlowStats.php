<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Settings;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\Concerns\HasDashboardPeriod;
use App\Support\Admin\DashboardMetrics;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline numbers for the selected period with sparklines and change vs the previous period.
 * Backed by DashboardMetrics (cached 5 min; AI counters 1 min).
 */
class FundsFlowStats extends StatsOverviewWidget
{
    use HasDashboardPeriod;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    /**
     * @return null|array<string, ?int>|int
     */
    protected function getColumns(): int|array|null
    {
        return ['@sm' => 2, '@xl' => 4, '!@sm' => 2, '!@lg' => 4];
    }

    protected function getStats(): array
    {
        $days = $this->period();
        $m = DashboardMetrics::overview($days);
        $ai = DashboardMetrics::aiToday();
        $usersUrl = UserResource::getUrl('index');

        $signups = array_values($m['signups']);
        $transactions = array_values($m['transactions']);
        $perActive = $m['active'] > 0 ? round($m['transactions_total'] / $m['active'], 1) : 0;

        return [
            Stat::make('Users', number_format($m['users']))
                ->description('+' . number_format($m['signups_total']) . " in the last {$days} days")
                ->descriptionIcon(Heroicon::UserPlus)
                ->icon(Heroicon::OutlinedUsers)
                ->chart($this->cumulative($m['users'], $signups))
                ->color('primary')
                ->url($usersUrl),
            $this->trendStat('New users', $m['signups_total'], $m['signups_previous'], $days)
                ->icon(Heroicon::OutlinedUserPlus)
                ->chart($signups)
                ->url(UserResource::getUrl('index', ['sort' => 'created_at:desc'])),
            Stat::make('Active users', number_format($m['active']))
                ->description(number_format($m['active_today']) . ' today · ' . number_format($m['active_telegram']) . ' via Telegram')
                ->descriptionIcon(Heroicon::Bolt)
                ->icon(Heroicon::OutlinedBolt)
                ->color('success'),
            $this->trendStat('Transactions added', $m['transactions_total'], $m['transactions_previous'], $days)
                ->icon(Heroicon::OutlinedBanknotes)
                ->chart($transactions),
            $ai['enabled']
                ? Stat::make('AI assists today', number_format($ai['used']) . ' / ' . number_format($ai['capacity']))
                    ->description($ai['users'] . ' ' . ($ai['users'] === 1 ? 'user' : 'users') . ' · today only (counters are not kept)')
                    ->descriptionIcon(Heroicon::Sparkles)
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color($ai['capacity'] > 0 && $ai['used'] / $ai['capacity'] > 0.8 ? 'warning' : 'info')
                    ->url(Settings::getUrl())
                : Stat::make('AI assists today', 'Off')
                    ->description('Turned off in Settings · ' . number_format($ai['used']) . ' used before')
                    ->descriptionIcon(Heroicon::NoSymbol)
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('gray')
                    ->url(Settings::getUrl()),
            Stat::make('Telegram linked', number_format($m['telegram']))
                ->description($m['users'] > 0 ? round($m['telegram'] / $m['users'] * 100) . '% of users' : '—')
                ->descriptionIcon(Heroicon::PaperAirplane)
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->url(UserResource::getUrl('index', ['filters' => ['telegram' => ['value' => 1]]])),
            Stat::make('Per active user', (string) $perActive)
                ->description("transactions in the last {$days} days")
                ->descriptionIcon(Heroicon::Calculator)
                ->icon(Heroicon::OutlinedChartBar),
            Stat::make('Blocked users', number_format($m['blocked']))
                ->description($m['blocked'] > 0 ? 'Tokens and Telegram access refused' : 'Nobody is blocked')
                ->descriptionIcon(Heroicon::NoSymbol)
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color($m['blocked'] > 0 ? 'danger' : 'gray')
                ->url(UserResource::getUrl('index', ['filters' => ['blocked_at' => ['value' => 1]]])),
        ];
    }

    private function trendStat(string $label, int $current, int $previous, int $days): Stat
    {
        [$pct, $direction] = DashboardMetrics::trend($current, $previous);

        $description = match (true) {
            $direction === 'flat' => "Same as the previous {$days} days",
            $pct === null => "Up from 0 in the previous {$days} days",
            default => ($direction === 'up' ? '+' : '−') . "{$pct}% vs previous {$days} days",
        };

        return Stat::make($label, number_format($current))
            ->description($description)
            ->descriptionIcon(match ($direction) {
                'up' => Heroicon::ArrowTrendingUp,
                'down' => Heroicon::ArrowTrendingDown,
                default => Heroicon::Minus,
            })
            ->color(match ($direction) {
                'up' => 'success',
                'down' => 'danger',
                default => 'gray',
            });
    }

    /**
     * Running user total for each day of the period, ending at today's total.
     *
     * @param list<int> $signups
     * @return list<int>
     */
    private function cumulative(int $total, array $signups): array
    {
        $running = $total - array_sum($signups);
        $out = [];

        foreach ($signups as $count) {
            $running += $count;
            $out[] = $running;
        }

        return $out;
    }
}
