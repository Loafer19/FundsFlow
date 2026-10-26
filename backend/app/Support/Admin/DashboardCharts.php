<?php

namespace App\Support\Admin;

use Carbon\CarbonImmutable;

/**
 * Shared Chart.js bits for the dashboard charts (bundled Chart.js, no external assets).
 */
final class DashboardCharts
{
    /** Readable on both the light and the dark theme. */
    public const COLORS = [
        'web' => '#3b82f6',
        'telegram' => '#0ea5e9',
        'recurring' => '#a855f7',
        'mcp' => '#10b981',
        'amber' => '#f59e0b',
        'gray' => '#9ca3af',
        'red' => '#ef4444',
        'github' => '#6b7280',
        'google' => '#ea4335',
    ];

    /**
     * @param list<string> $days Y-m-d
     * @return list<string>
     */
    public static function labels(array $days): array
    {
        return array_map(fn (string $d): string => CarbonImmutable::parse($d)->format('M j'), $days);
    }

    /**
     * @return array<string, mixed>
     */
    public static function axisOptions(bool $legend = true, bool $stacked = false): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => $legend, 'position' => 'bottom'],
                'tooltip' => ['mode' => 'index', 'intersect' => false],
            ],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => [
                    'stacked' => $stacked,
                    'grid' => ['display' => false],
                    'ticks' => ['maxTicksLimit' => 10, 'autoSkip' => true],
                ],
                'y' => [
                    'stacked' => $stacked,
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }
}
