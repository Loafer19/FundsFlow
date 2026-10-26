<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AdminAuditLogs\AdminAuditLogResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\AdminAuditLog;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentAdminActivity extends TableWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent admin actions')
            ->query(fn () => AdminAuditLog::query()->with(['admin:id,email', 'user:id,name,email'])->latest('id')->limit(8))
            ->paginated(false)
            ->emptyStateHeading('No admin actions yet')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip(),
                TextColumn::make('admin.email')->label('Admin')->placeholder('console'),
                TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', $state))
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'delete') => 'danger',
                        str_contains($state, 'block') => 'warning',
                        str_contains($state, 'settings') => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('user.email')
                    ->label('User')
                    ->placeholder('—')
                    ->limit(36)
                    ->url(fn (AdminAuditLog $record): ?string => $record->user
                        ? UserResource::getUrl('view', ['record' => $record->user])
                        : null),
                TextColumn::make('meta')
                    ->label('Details')
                    ->state(fn (AdminAuditLog $record): ?string => $record->meta ? json_encode($record->meta, JSON_UNESCAPED_UNICODE) : null)
                    ->limit(60)
                    ->color('gray')
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('all')
                    ->label('Full audit log')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->link()
                    ->url(AdminAuditLogResource::getUrl('index')),
            ]);
    }
}
