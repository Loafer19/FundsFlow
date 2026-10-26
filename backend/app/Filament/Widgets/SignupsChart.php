<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasDashboardPeriod;
use App\Support\Admin\DashboardCharts;
use App\Support\Admin\DashboardMetrics;
use Filament\Widgets\ChartWidget;

class SignupsChart extends ChartWidget
{
    use HasDashboardPeriod;

    protected static ?int $sort = 2;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return 'Sign-ups per day';
    }

    public function getDescription(): string
    {
        $m = DashboardMetrics::overview($this->period());

        return number_format($m['signups_total']) . " new accounts in the last {$this->period()} days (UTC days)";
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $signups = DashboardMetrics::overview($this->period())['signups'];

        return [
            'datasets' => [[
                'label' => 'Sign-ups',
                'data' => array_values($signups),
                'borderColor' => '#f59e0b',
                'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                'pointBackgroundColor' => '#f59e0b',
                'pointRadius' => $this->period() > 30 ? 0 : 2,
                'fill' => true,
                'tension' => 0.35,
            ]],
            'labels' => DashboardCharts::labels(array_keys($signups)),
        ];
    }

    protected function getOptions(): array
    {
        return DashboardCharts::axisOptions(legend: false);
    }
}
