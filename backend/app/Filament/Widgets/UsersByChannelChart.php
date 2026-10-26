<?php

namespace App\Filament\Widgets;

use App\Support\Admin\DashboardCharts;
use App\Support\Admin\DashboardMetrics;
use Filament\Widgets\ChartWidget;

/**
 * All-time split of accounts by channel, or by sign-in method (an account can have several).
 */
class UsersByChannelChart extends ChartWidget
{
    public ?string $filter = 'channel';

    protected static ?int $sort = 4;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return 'Users by channel';
    }

    public function getDescription(): string
    {
        return $this->filter === 'login'
            ? 'Sign-in methods linked to accounts (one account can have several)'
            : 'Web = real email or Google/GitHub login · Telegram = linked chat';
    }

    protected function getFilters(): ?array
    {
        return [
            'channel' => 'Web vs Telegram',
            'login' => 'Sign-in method',
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $c = DashboardMetrics::channels();
        $colors = DashboardCharts::COLORS;

        $slices = $this->filter === 'login'
            ? [
                'Email + password' => [$c['password'], $colors['amber']],
                'Google' => [$c['google'], $colors['google']],
                'GitHub' => [$c['github'], $colors['github']],
                'Telegram' => [$c['telegram'], $colors['telegram']],
            ]
            : [
                'Web only' => [$c['web_only'], $colors['web']],
                'Telegram only' => [$c['telegram_only'], $colors['telegram']],
                'Both' => [$c['both'], $colors['mcp']],
                'Other (unlinked)' => [$c['neither'], $colors['gray']],
            ];

        $slices = array_filter($slices, fn (array $s): bool => $s[0] > 0);

        if ($slices === []) {
            return [];
        }

        return [
            'datasets' => [[
                'data' => array_column($slices, 0),
                'backgroundColor' => array_column($slices, 1),
                'borderWidth' => 0,
                'hoverOffset' => 6,
            ]],
            'labels' => array_keys($slices),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '62%',
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'right'],
            ],
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }
}
