<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Resources\Users\UserResource;
use App\Models\MonobankAccount;
use App\Models\MonobankConnection;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('name'),
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('telegram')
                            ->state(fn (User $record): ?string => UserResource::telegramLabel($record))
                            ->placeholder('Not linked'),
                        TextEntry::make('sign_in')
                            ->label('Sign-in methods')
                            ->state(fn (User $record): string => collect(['password'])
                                ->merge($record->identities->pluck('provider'))
                                ->unique()
                                ->implode(', ')),
                        TextEntry::make('timezone')->state(fn (User $record): string => $record->timezone()),
                        TextEntry::make('created_at')->label('Joined')->dateTime(),
                        TextEntry::make('last_activity')
                            ->label('Last API activity')
                            ->state(fn (User $record) => $record->tokens()->max('last_used_at'))
                            ->since()
                            ->placeholder('—'),
                    ]),
                Section::make('Access & AI')
                    ->columnSpan(1)
                    ->schema([
                        IconEntry::make('is_admin')->label('Admin')->boolean()->trueColor('warning')->falseColor('gray'),
                        IconEntry::make('mfa')
                            ->label('Authenticator app')
                            ->state(fn (User $record): bool => filled($record->getAppAuthenticationSecret()))
                            ->boolean()
                            ->falseColor('gray'),
                        TextEntry::make('blocked_at')->label('Blocked')->dateTime()->placeholder('No'),
                        TextEntry::make('ai_today')
                            ->label('AI assists today')
                            ->state(function (User $record): string {
                                $quota = app(TelegramAiQuota::class);
                                $bonus = $quota->bonus($record);

                                return $quota->used($record) . ' / ' . $quota->dailyLimit($record) . ($bonus > 0 ? " (incl. +{$bonus} today)" : '');
                            }),
                        TextEntry::make('ai_daily_limit')
                            ->label('Daily limit')
                            ->state(fn (User $record): string => $record->ai_daily_limit === null
                                ? 'Default (' . app(TelegramAiQuota::class)->defaultLimit() . ')'
                                : (string) $record->ai_daily_limit),
                    ]),
                Section::make('Data')
                    ->columns(5)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('transactions_count')->label('Transactions')->state(fn (User $r): int => $r->transactions()->count()),
                        TextEntry::make('tags_count')->label('Tags')->state(fn (User $r): int => $r->tags()->count()),
                        TextEntry::make('budgets_count')->label('Budgets')->state(fn (User $r): int => $r->budgets()->count()),
                        TextEntry::make('recurring_count')->label('Recurring')->state(fn (User $r): int => $r->recurringTransactions()->count()),
                        TextEntry::make('attachments_count')->label('Attachments')->state(fn (User $r): int => $r->attachments()->count()),
                    ]),
                Section::make('Monobank')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('mono_connected')
                            ->label('Connected')
                            ->state(fn (User $record): string => self::connection($record) ? 'Yes' : 'No')
                            ->badge()
                            ->color(fn (User $record): string => self::connection($record) ? 'success' : 'gray')
                            ->placeholder('Not connected'),
                        TextEntry::make('mono_status')
                            ->label('Status')
                            ->state(fn (User $record): ?string => self::connection($record)?->status)
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                MonobankConnection::STATUS_ACTIVE => 'Active',
                                MonobankConnection::STATUS_SYNCING => 'Syncing',
                                MonobankConnection::STATUS_ERROR => 'Error',
                                default => $state ? ucfirst($state) : '—',
                            })
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                MonobankConnection::STATUS_ACTIVE => 'success',
                                MonobankConnection::STATUS_SYNCING => 'info',
                                MonobankConnection::STATUS_ERROR => 'danger',
                                default => 'gray',
                            })
                            ->visible(fn (User $record): bool => self::connection($record) !== null),
                        TextEntry::make('mono_last_synced_at')
                            ->label('Last synced')
                            ->state(fn (User $record) => self::connection($record)?->last_synced_at)
                            ->dateTime()
                            ->placeholder('Never')
                            ->visible(fn (User $record): bool => self::connection($record) !== null),
                        IconEntry::make('mono_notify')
                            ->label('Notify')
                            ->state(fn (User $record): bool => (bool) (self::connection($record)?->notify))
                            ->boolean()
                            ->falseColor('gray')
                            ->visible(fn (User $record): bool => self::connection($record) !== null),
                        TextEntry::make('mono_cards')
                            ->label('Cards')
                            ->state(function (User $record): string {
                                $cards = self::accounts($record)->filter(fn (MonobankAccount $account): bool => !$account->isJar());

                                return $cards->where('enabled', true)->count() . ' / ' . $cards->count();
                            })
                            ->helperText('Enabled / total')
                            ->visible(fn (User $record): bool => self::connection($record) !== null),
                        TextEntry::make('mono_jars')
                            ->label('Jars')
                            ->state(fn (User $record): int => self::accounts($record)->filter(fn (MonobankAccount $account): bool => $account->isJar())->count())
                            ->visible(fn (User $record): bool => self::connection($record) !== null),
                        TextEntry::make('mono_linked_goals')
                            ->label('Linked jar goals')
                            ->state(fn (User $record): int => $record->budgets()
                                ->whereNotNull('monobank_jar_id')
                                ->whereNull('monobank_jar_unlinked_at')
                                ->count())
                            ->visible(fn (User $record): bool => self::connection($record) !== null),
                        TextEntry::make('mono_last_error')
                            ->label('Last error')
                            ->state(fn (User $record): ?string => self::connection($record)?->last_error)
                            ->placeholder('—')
                            ->color(fn (User $record): string => filled(self::connection($record)?->last_error) ? 'danger' : 'gray')
                            ->columnSpanFull()
                            ->visible(fn (User $record): bool => self::connection($record) !== null && filled(self::connection($record)?->last_error)),
                    ]),
            ]);
    }

    private static function connection(User $user): ?MonobankConnection
    {
        $user->loadMissing('monobankConnection.accounts');

        return $user->monobankConnection;
    }

    /**
     * @return \Illuminate\Support\Collection<int, MonobankAccount>
     */
    private static function accounts(User $user)
    {
        return self::connection($user)?->accounts ?? collect();
    }
}
