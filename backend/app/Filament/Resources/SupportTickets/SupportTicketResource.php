<?php

namespace App\Filament\Resources\SupportTickets;

use App\Filament\Resources\SupportTickets\Actions\SupportTicketActions;
use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Filament\Resources\Users\UserResource;
use App\Models\SupportTicket;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Support requests from the web app (Feedback / Report a problem), /support in the bot and
 * the assistant. Admins read the thread and reply; the reply reaches the user in Telegram
 * (when linked) and in the web app (Settings → Support).
 */
class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Support';

    protected static ?string $modelLabel = 'support request';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'subject';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $new = SupportTicket::query()->where('status', SupportTicket::STATUS_NEW)->count();

        return $new > 0 ? (string) $new : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            SupportTicket::STATUS_NEW => 'warning',
            SupportTicket::STATUS_IN_PROGRESS => 'info',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,email,name')->withCount('messages'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('subject')
                    ->searchable()
                    ->limit(60)
                    ->description(fn (SupportTicket $record): string => $record->user?->email ?? 'deleted user'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SupportTicket::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('source')->badge()->color('gray'),
                TextColumn::make('messages_count')->label('Replies')->numeric(),
                TextColumn::make('user.email')->label('User')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Opened')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Last activity')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(SupportTicket::STATUSES),
                SelectFilter::make('source')->options(['web' => 'Web', 'telegram' => 'Telegram']),
            ])
            ->recordActions([
                ViewAction::make(),
                SupportTicketActions::reply(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Conversation')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('body')
                            ->label(fn (SupportTicket $record): string => 'User · ' . $record->created_at?->format('Y-m-d H:i'))
                            ->prose()
                            ->extraAttributes(['style' => 'white-space: pre-wrap']),
                        RepeatableEntry::make('messages')
                            ->hiddenLabel()
                            ->placeholder('No replies yet')
                            ->schema([
                                TextEntry::make('body')
                                    ->label(fn ($record): string => ($record->author === 'admin' ? 'Support' : 'User') . ' · ' . $record->created_at?->format('Y-m-d H:i'))
                                    ->prose()
                                    ->extraAttributes(['style' => 'white-space: pre-wrap']),
                            ]),
                    ]),
                Section::make('Request')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => SupportTicket::STATUSES[$state] ?? $state)
                            ->color(fn (string $state): string => self::statusColor($state)),
                        TextEntry::make('user.email')
                            ->label('User')
                            ->url(fn (SupportTicket $record): ?string => $record->user ? UserResource::getUrl('view', ['record' => $record->user]) : null)
                            ->placeholder('deleted user'),
                        TextEntry::make('telegram')
                            ->state(fn (SupportTicket $record): ?string => $record->user ? UserResource::telegramLabel($record->user->loadMissing('identities')) : null)
                            ->placeholder('Not linked: replies only show in the web app'),
                        TextEntry::make('source')->badge()->color('gray'),
                        TextEntry::make('app_version')->label('App version')->placeholder('—'),
                        TextEntry::make('user_agent')->label('Browser')->placeholder('—')->size('xs'),
                        TextEntry::make('created_at')->label('Opened')->dateTime(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportTickets::route('/'),
            'view' => ViewSupportTicket::route('/{record}'),
        ];
    }
}
