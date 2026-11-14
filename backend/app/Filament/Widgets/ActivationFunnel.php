<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasDashboardPeriod;
use App\Support\Admin\DashboardMetrics;
use Filament\Widgets\Widget;

/**
 * Signup → activity → web → first transaction, plus median time and a by-method split.
 */
class ActivationFunnel extends Widget
{
    use HasDashboardPeriod;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.activation-funnel';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return DashboardMetrics::funnel($this->period());
    }
}
