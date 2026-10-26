<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\UserAdminActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->limit(28)
                    ->description(fn (User $record): ?string => $record->isBlocked() ? 'Blocked' : null),
                TextColumn::make('email')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // Email, name or Telegram @username (identities.meta.username).
                        $term = '%' . ltrim($search, '@') . '%';

                        return $query->where(fn (Builder $q) => $q
                            ->where('email', 'like', $term)
                            ->orWhere('name', 'like', $term)
                            ->orWhereHas('identities', fn (Builder $i) => $i
                                ->where('provider', 'telegram')
                                ->where(fn (Builder $t) => $t
                                    ->where('meta->username', 'like', $term)
                                    ->orWhere('external_id', 'like', $term))));
                    })
                    ->copyable(),
                TextColumn::make('telegram')
                    ->label('Telegram')
                    ->state(fn (User $record): ?string => UserResource::telegramLabel($record))
                    ->placeholder('—'),
                IconColumn::make('is_admin')->label('Admin')->boolean()->trueColor('warning')->falseColor('gray'),
                TextColumn::make('ai_today')
                    ->label('AI today')
                    ->state(function (User $record): string {
                        $quota = app(TelegramAiQuota::class);

                        return $quota->used($record) . ' / ' . $quota->dailyLimit($record);
                    })
                    ->badge()
                    ->color(fn (User $record): string => app(TelegramAiQuota::class)->hasQuota($record) ? 'gray' : 'danger'),
                TextColumn::make('transactions_count')->label('Transactions')->numeric()->sortable(),
                TextColumn::make('tokens_max_last_used_at')
                    ->label('Last API activity')
                    ->since()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')->label('Joined')->date()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_admin')->label('Admin'),
                TernaryFilter::make('telegram')
                    ->label('Telegram linked')
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('identities', fn (Builder $i) => $i->where('provider', 'telegram')),
                        false: fn (Builder $q) => $q->whereDoesntHave('identities', fn (Builder $i) => $i->where('provider', 'telegram')),
                    ),
                TernaryFilter::make('blocked_at')->label('Blocked')->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(UserAdminActions::all()),
            ])
            ->toolbarActions([]);
    }
}
