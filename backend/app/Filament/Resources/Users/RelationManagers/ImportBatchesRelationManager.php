<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\TransactionSource;
use App\Models\ImportBatch;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImportBatchesRelationManager extends ReadOnlyRelationManager
{
    protected static string $relationship = 'importBatches';

    protected static ?string $title = 'Imports';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Created')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('filename')
                    ->label('File')
                    ->limit(40)
                    ->placeholder('—')
                    ->description(fn (ImportBatch $record): ?string => $record->format),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(function (mixed $state): string {
                        $source = TransactionSource::tryFromMixed($state);

                        return $source?->label() ?? ($state ? ucfirst((string) $state) : '—');
                    })
                    ->color(fn (mixed $state): string => TransactionSource::tryFromMixed($state)?->badgeColor() ?? 'gray'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        ImportBatch::STATUS_PREVIEWING => 'Preview',
                        ImportBatch::STATUS_COMMITTING => 'Committing',
                        ImportBatch::STATUS_COMMITTED => 'Committed',
                        ImportBatch::STATUS_UNDONE => 'Undone',
                        ImportBatch::STATUS_FAILED => 'Failed',
                        default => $state ? ucfirst($state) : '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        ImportBatch::STATUS_PREVIEWING => 'info',
                        ImportBatch::STATUS_COMMITTING => 'warning',
                        ImportBatch::STATUS_COMMITTED => 'success',
                        ImportBatch::STATUS_UNDONE => 'gray',
                        ImportBatch::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (ImportBatch $record): ?string => $record->status === ImportBatch::STATUS_FAILED
                        ? $record->error_message
                        : null),
                TextColumn::make('total_rows')->label('Rows')->numeric(),
                TextColumn::make('new_count')->label('Created')->numeric(),
                TextColumn::make('duplicate_count')->label('Duplicates')->numeric(),
                TextColumn::make('transfer_count')->label('Transfers')->numeric(),
                TextColumn::make('committed_at')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('undone_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    ImportBatch::STATUS_PREVIEWING => 'Preview',
                    ImportBatch::STATUS_COMMITTING => 'Committing',
                    ImportBatch::STATUS_COMMITTED => 'Committed',
                    ImportBatch::STATUS_UNDONE => 'Undone',
                    ImportBatch::STATUS_FAILED => 'Failed',
                ]),
            ])
            ->paginated([10, 25, 50]);
    }
}
