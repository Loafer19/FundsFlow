<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\Admin\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait HasDashboardPeriod
{
    use InteractsWithPageFilters;

    protected function period(): int
    {
        return DashboardMetrics::normalizePeriod($this->pageFilters['period'] ?? null);
    }
}
