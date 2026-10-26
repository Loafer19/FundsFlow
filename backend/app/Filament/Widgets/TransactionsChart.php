<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasDashboardPeriod;
use App\Support\Admin\DashboardCharts;
use App\Support\Admin\DashboardMetrics;
use Filament\Widgets\ChartWidget;

class TransactionsChart extends ChartWidget
{
    use HasDashboardPeriod;

    private const LABELS = [
        'web' => 'Web app',
        'telegram' => 'Telegram',
        'mcp' => 'MCP / AI clients',
        'recurring' => 'Recurring',
    ];

    protected static ?int $sort = 3;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return 'Transactions added per day';
    }

    public function getDescription(): string
    {
        return 'By where they were created · last ' . $this->period() . ' days';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $bySource = DashboardMetrics::transactionsBySource($this->period());
        $datasets = [];

        foreach ($bySource as $source => $days) {
            $datasets[] = [
                'label' => self::LABELS[$source] ?? ucfirst($source),
                'data' => array_values($days),
                'backgroundColor' => DashboardCharts::COLORS[$source] ?? DashboardCharts::COLORS['gray'],
                'borderRadius' => 3,
                'maxBarThickness' => 18,
            ];
        }

        $days = array_keys(reset($bySource) ?: DashboardMetrics::emptyDays($this->period()));

        return [
            'datasets' => $datasets,
            'labels' => DashboardCharts::labels($days),
        ];
    }

    protected function getOptions(): array
    {
        return DashboardCharts::axisOptions(legend: true, stacked: true);
    }
}
