<?php

namespace App\Filament\Widgets;

use App\Enums\TransactionSource;
use App\Filament\Widgets\Concerns\HasDashboardPeriod;
use App\Support\Admin\DashboardCharts;
use App\Support\Admin\DashboardMetrics;
use Filament\Widgets\ChartWidget;

class TransactionsChart extends ChartWidget
{
    use HasDashboardPeriod;

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
            $enum = TransactionSource::tryFrom((string) $source);

            $datasets[] = [
                'label' => $enum?->label() ?? ucfirst((string) $source),
                'data' => array_values($days),
                'backgroundColor' => $enum?->chartColor() ?? DashboardCharts::colorFor((string) $source),
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
