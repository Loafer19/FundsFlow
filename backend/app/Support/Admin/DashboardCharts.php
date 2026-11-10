<?php

namespace App\Support\Admin;

use App\Enums\TransactionSource;
use Carbon\CarbonImmutable;

/**
 * Shared Chart.js bits for the dashboard charts (bundled Chart.js, no external assets).
 */
final class DashboardCharts
{
    /** Non-source colors, readable on both the light and the dark theme. */
    private const NAMED = [
        'amber' => '#f59e0b',
        'gray' => '#9ca3af',
        'red' => '#ef4444',
        'github' => '#6b7280',
        'google' => '#ea4335',
    ];

    /**
     * Chart colors, including every TransactionSource (new cases fail the enum match
     * instead of rendering gray).
     *
     * @return array<string, string>
     */
    public static function colors(): array
    {
        $colors = self::NAMED;

        foreach (TransactionSource::cases() as $source) {
            $colors[$source->value] = $source->chartColor();
        }

        return $colors;
    }

    public static function colorFor(string $source): string
    {
        $colors = self::colors();

        return $colors[$source] ?? $colors['gray'];
    }

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
