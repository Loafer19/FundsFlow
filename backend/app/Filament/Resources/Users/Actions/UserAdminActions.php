<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use App\Support\Admin\AdminAudit;
use App\Support\TelegramAiQuota;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Account-level admin actions (no financial data is ever edited). Shared by the users
 * table and the user view page; every action is written to the audit log.
 */
final class UserAdminActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::resetQuota(),
            self::grantBonus(),
            self::setLimit(),
            self::toggleAdmin(),
            self::toggleBlock(),
        ];
    }

    public static function resetQuota(): Action
    {
        return Action::make('resetAiQuota')
            ->label('Reset AI quota today')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => 'Sets today\'s used AI assists for ' . $record->email . ' back to 0.')
            ->action(function (User $record): void {
                $quota = app(TelegramAiQuota::class);
                $before = $quota->used($record);
                $quota->reset($record);
                AdminAudit::log('ai_quota_reset', $record, ['used_before' => $before]);
                Notification::make()->title('AI quota reset')->success()->send();
            });
    }

    public static function grantBonus(): Action
    {
        return Action::make('grantAiBonus')
            ->label('Give extra AI assists today')
            ->icon(Heroicon::OutlinedGift)
            ->schema([
                TextInput::make('assists')
                    ->label('Extra assists (today only)')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(500)
                    ->default(5)
                    ->required(),
            ])
            ->action(function (User $record, array $data): void {
                $assists = (int) $data['assists'];
                app(TelegramAiQuota::class)->grantBonus($record, $assists);
                AdminAudit::log('ai_bonus', $record, ['assists' => $assists]);
                Notification::make()->title("+{$assists} AI assists for today")->success()->send();
            });
    }

    public static function setLimit(): Action
    {
        return Action::make('setAiLimit')
            ->label('Set daily AI limit')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->fillForm(fn (User $record): array => ['ai_daily_limit' => $record->ai_daily_limit])
            ->schema([
                TextInput::make('ai_daily_limit')
                    ->label('AI assists per day')
                    ->helperText(fn (): string => 'Empty = server default (' . app(TelegramAiQuota::class)->defaultLimit() . '). 0 turns AI off for this user.')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(10000)
                    ->nullable(),
            ])
            ->action(function (User $record, array $data): void {
                $old = $record->ai_daily_limit;
                $new = ($data['ai_daily_limit'] ?? null) === null || $data['ai_daily_limit'] === '' ? null : (int) $data['ai_daily_limit'];
                $record->forceFill(['ai_daily_limit' => $new])->save();
                AdminAudit::log('ai_limit_set', $record, ['from' => $old, 'to' => $new]);
                Notification::make()->title('Daily AI limit: ' . ($new ?? 'default'))->success()->send();
            });
    }

    public static function toggleAdmin(): Action
    {
        return Action::make('toggleAdmin')
            ->label(fn (User $record): string => $record->is_admin ? 'Remove admin' : 'Make admin')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color(fn (User $record): string => $record->is_admin ? 'danger' : 'gray')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => $record->is_admin
                ? $record->email . ' will lose access to this panel.'
                : $record->email . ' gets full access to this panel. They sign in with their email + password and must set up an authenticator app.')
            ->hidden(fn (User $record): bool => $record->is(auth()->user()))
            ->action(function (User $record): void {
                $record->forceFill(['is_admin' => !$record->is_admin])->save();
                AdminAudit::log($record->is_admin ? 'admin_granted' : 'admin_revoked', $record);
                Notification::make()->title($record->is_admin ? 'Now an admin' : 'Admin removed')->success()->send();
            });
    }

    public static function toggleBlock(): Action
    {
        return Action::make('toggleBlock')
            ->label(fn (User $record): string => $record->isBlocked() ? 'Unblock' : 'Block')
            ->icon(fn (User $record) => $record->isBlocked() ? Heroicon::OutlinedLockOpen : Heroicon::OutlinedNoSymbol)
            ->color(fn (User $record): string => $record->isBlocked() ? 'gray' : 'danger')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => $record->isBlocked()
                ? $record->email . ' can sign in and use the bot again.'
                : $record->email . ' is signed out everywhere (web, Mini App, MCP) and the bot only answers "Account blocked". Data is kept.')
            ->hidden(fn (User $record): bool => $record->is(auth()->user()))
            ->action(function (User $record): void {
                $blocking = !$record->isBlocked();
                $record->forceFill(['blocked_at' => $blocking ? now() : null])->save();
                AdminAudit::log($blocking ? 'user_blocked' : 'user_unblocked', $record);
                Notification::make()->title($blocking ? 'User blocked' : 'User unblocked')->success()->send();
            });
    }
}
