<?php

namespace App\Filament\Resources\Users\Actions;

use App\Actions\Admin\DeleteUserAction;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use App\Support\TelegramAiQuota;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Account-level admin actions (no financial data is ever edited; delete removes the whole
 * account). Shared by the users table and the user view page; every action is written to
 * the audit log.
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

    /**
     * Permanently delete one account (see DeleteUserAction). The admin must type the email.
     * Hidden for yourself and for other admins (remove their admin role first).
     */
    public static function delete(): Action
    {
        return Action::make('deleteUser')
            ->label('Delete user')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedTrash)
            ->modalHeading(fn (User $record): string => 'Delete ' . $record->email . '?')
            ->modalDescription('This permanently deletes the account and all of its data: transactions, '
                . 'receipt attachments (files too), tags, budgets, recurring rules, Telegram/Google/GitHub links '
                . 'and API/MCP tokens. It cannot be undone. Consider "Block" if you only want to stop access.')
            ->modalSubmitActionLabel('Delete permanently')
            ->schema([
                TextInput::make('confirm_email')
                    ->label('Type the user\'s email to confirm')
                    ->required()
                    ->autocomplete('off')
                    ->rules([
                        fn (User $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                            if (mb_strtolower(trim((string) $value)) !== mb_strtolower($record->email)) {
                                $fail('The email does not match.');
                            }
                        },
                    ]),
            ])
            ->hidden(fn (User $record): bool => !UserResource::canDelete($record))
            ->action(function (User $record, array $data, Action $action, Component $livewire): void {
                if (mb_strtolower(trim((string) ($data['confirm_email'] ?? ''))) !== mb_strtolower($record->email)) {
                    Notification::make()->title('The email does not match')->danger()->send();
                    $action->halt();
                }

                $email = $record->email;

                try {
                    $counts = app(DeleteUserAction::class)->execute($record, self::admin());
                } catch (\DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title("Deleted {$email}")
                    ->body("{$counts['transactions']} transactions, {$counts['tags']} tags, {$counts['budgets']} budgets, {$counts['files']} files removed.")
                    ->success()
                    ->send();

                if ($livewire instanceof ViewUser) {
                    $livewire->redirect(UserResource::getUrl('index'), navigate: true);
                }
            });
    }

    /**
     * Bulk delete for the users table. Admins and yourself are skipped (never deleted).
     */
    public static function bulkDelete(): BulkAction
    {
        return BulkAction::make('deleteUsers')
            ->label('Delete selected users')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading(fn (?Collection $records): string => 'Permanently delete ' . ($records?->count() ?? 0) . ' user(s)?')
            ->modalDescription('Every selected account is deleted with ALL of its data (transactions, attachment files, tags, '
                . 'budgets, recurring rules, Telegram/OAuth links, API/MCP tokens). This cannot be undone. '
                . 'Admins and your own account are skipped.')
            ->schema([
                TextInput::make('confirm')
                    ->label('Type DELETE to confirm')
                    ->required()
                    ->autocomplete('off')
                    ->in(['DELETE'])
                    ->validationMessages(['in' => 'Type DELETE (capital letters) to confirm.']),
            ])
            ->modalSubmitActionLabel('Delete permanently')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $admin = self::admin();
                $action = app(DeleteUserAction::class);
                $deleted = [];
                $skipped = [];

                foreach ($records as $user) {
                    /** @var User $user */
                    if (DeleteUserAction::denialReason($user, $admin) !== null) {
                        $skipped[] = $user->email;

                        continue;
                    }

                    try {
                        $action->execute($user, $admin);
                        $deleted[] = $user->email;
                    } catch (\Throwable $e) {
                        report($e);
                        $skipped[] = $user->email;
                    }
                }

                Notification::make()
                    ->title('Deleted ' . count($deleted) . ' user(s)')
                    ->body($skipped === [] ? null : 'Skipped: ' . implode(', ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? '…' : ''))
                    ->status($skipped === [] ? 'success' : 'warning')
                    ->send();
            });
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
