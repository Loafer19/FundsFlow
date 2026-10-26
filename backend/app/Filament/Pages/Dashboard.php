<?php

namespace App\Filament\Pages;

use App\Support\Admin\DashboardMetrics;
use Filament\Actions\Action;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * /admin home: period switch (7 / 30 / 90 days, kept in the URL and session) shared by
 * every widget through InteractsWithPageFilters.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                ToggleButtons::make('period')
                    ->hiddenLabel()
                    ->options(collect(DashboardMetrics::PERIODS)->mapWithKeys(fn (int $d): array => [(string) $d => "{$d} days"])->all())
                    ->default((string) DashboardMetrics::DEFAULT_PERIOD)
                    ->grouped(),
            ]);
    }

    /**
     * @return array<string, ?int>|int
     */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->tooltip('Stats are cached for 5 minutes')
                ->action(function (): void {
                    DashboardMetrics::flush();
                    $this->redirect(static::getUrl(['filters' => $this->filters]), navigate: true);
                }),
        ];
    }
}
