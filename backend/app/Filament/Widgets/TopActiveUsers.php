<?php

namespace App\Filament\Widgets;

use App\Enums\TransactionSource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\Concerns\HasDashboardPeriod;
use App\Models\User;
use App\Support\Admin\DashboardMetrics;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopActiveUsers extends TableWidget
{
    use HasDashboardPeriod;

    protected static ?int $sort = 7;

    public function table(Table $table): Table
    {
        $days = $this->period();
        $since = DashboardMetrics::periodStart($days);
        $inPeriod = fn (Builder $q) => $q->where('created_at', '>=', $since);

        return $table
            ->heading('Most active users')
            ->description("By transactions added in the last {$days} days")
            ->query(fn () => User::query()
                ->select(['id', 'name', 'email', 'blocked_at'])
                ->withCount([
                    'transactions as period_count' => $inPeriod,
                    'transactions as telegram_count' => fn (Builder $q) => $inPeriod($q)->where('source', TransactionSource::Telegram->value),
                ])
                ->withMax('transactions as last_added_at', 'created_at')
                ->whereHas('transactions', $inPeriod)
                ->orderByDesc('period_count')
                ->limit(8))
            ->paginated(false)
            ->emptyStateHeading('No transactions in this period')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->columns([
                TextColumn::make('rank')->label('#')->rowIndex()->color('gray'),
                TextColumn::make('email')
                    ->label('User')
                    ->state(fn (User $record): string => str_ends_with($record->email, '@fundsflow.invalid') ? $record->name : $record->email)
                    ->description(fn (User $record): ?string => $record->blocked_at ? 'Blocked' : null)
                    ->limit(32)
                    ->weight('medium'),
                TextColumn::make('period_count')
                    ->label('Added')
                    ->numeric()
                    ->badge()
                    ->color('success'),
                TextColumn::make('telegram_count')
                    ->label('via Telegram')
                    ->state(fn (User $record): string => $record->period_count > 0
                        ? round($record->telegram_count / $record->period_count * 100) . '%'
                        : '—')
                    ->color('gray'),
                TextColumn::make('last_added_at')->label('Last added')->since()->placeholder('—'),
            ])
            ->recordUrl(fn (User $record): string => UserResource::getUrl('view', ['record' => $record]));
    }
}
