<?php

namespace App\Filament\Resources\SupportTickets\Actions;

use App\Actions\Support\CreateSupportTicketAction;
use App\Actions\Support\ReplyToSupportTicketAction;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class SupportTicketActions
{
    public static function reply(): Action
    {
        return Action::make('reply')
            ->label('Reply')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->modalHeading(fn (SupportTicket $record): string => 'Reply to #' . $record->id)
            ->modalDescription(fn (SupportTicket $record): string => $record->subject)
            ->schema([
                Textarea::make('body')
                    ->label('Message')
                    ->rows(6)
                    ->maxLength(CreateSupportTicketAction::BODY_MAX)
                    ->required(),
                Select::make('status')
                    ->label('Then mark as')
                    ->options([
                        SupportTicket::STATUS_IN_PROGRESS => 'In progress',
                        SupportTicket::STATUS_CLOSED => 'Closed',
                    ])
                    ->default(SupportTicket::STATUS_IN_PROGRESS)
                    ->required()
                    ->native(false),
            ])
            ->action(function (SupportTicket $record, array $data): void {
                /** @var User $admin */
                $admin = auth()->user();
                $result = app(ReplyToSupportTicketAction::class)->fromAdmin($record, $admin, (string) $data['body'], (string) $data['status']);
                AdminAudit::log('support_reply', $record->user, ['ticket_id' => $record->id, 'status' => $record->status]);

                Notification::make()
                    ->title('Reply sent')
                    ->body($result['telegram']
                        ? 'Delivered in Telegram and shown in the web app.'
                        : 'No Telegram linked: the user sees it in the web app (Settings → Support).')
                    ->success()
                    ->send();
            });
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label('Close')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->hidden(fn (SupportTicket $record): bool => $record->status === SupportTicket::STATUS_CLOSED)
            ->action(fn (SupportTicket $record) => self::setStatus($record, SupportTicket::STATUS_CLOSED));
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('Mark in progress')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->hidden(fn (SupportTicket $record): bool => $record->status === SupportTicket::STATUS_IN_PROGRESS)
            ->action(fn (SupportTicket $record) => self::setStatus($record, SupportTicket::STATUS_IN_PROGRESS));
    }

    private static function setStatus(SupportTicket $record, string $status): void
    {
        $from = $record->status;
        $record->forceFill(['status' => $status])->save();
        AdminAudit::log('support_status', $record->user, ['ticket_id' => $record->id, 'from' => $from, 'to' => $status]);
        Notification::make()->title('Marked ' . strtolower(SupportTicket::STATUSES[$status]))->success()->send();
    }
}
