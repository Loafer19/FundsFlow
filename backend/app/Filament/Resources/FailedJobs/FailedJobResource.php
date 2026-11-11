<?php

namespace App\Filament\Resources\FailedJobs;

use App\Filament\Resources\FailedJobs\Pages\ListFailedJobs;
use App\Models\FailedJob;
use App\Support\Admin\AdminAudit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\HtmlString;

class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Failed jobs';

    protected static ?string $modelLabel = 'failed job';

    protected static ?int $navigationSort = 8;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = FailedJob::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No failed jobs')
            ->columns([
                TextColumn::make('failed_at')->label('Failed')->dateTime()->sortable(),
                TextColumn::make('job')->label('Job')
                    ->state(fn (FailedJob $record): string => class_basename($record->jobName()))
                    ->tooltip(fn (FailedJob $record): string => $record->jobName()),
                TextColumn::make('error')->label('Error')
                    ->state(fn (FailedJob $record): string => $record->exceptionSummary())
                    ->limit(90)
                    ->wrap(),
                TextColumn::make('queue')->badge(),
                TextColumn::make('connection')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uuid')->label('UUID')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Details')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->modalHeading(fn (FailedJob $record): string => class_basename($record->jobName()))
                    ->modalContent(fn (FailedJob $record) => new HtmlString(
                        '<pre style="white-space:pre-wrap;font-size:12px;max-height:60vh;overflow:auto">'
                        . e(mb_substr($record->exception, 0, 20000)) . '</pre>',
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('retry')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->action(fn (FailedJob $record) => self::retry(collect([$record]))),
                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (FailedJob $record) => self::forget(collect([$record]))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retrySelected')
                        ->label('Retry selected')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => self::retry($records)),
                    BulkAction::make('deleteSelected')
                        ->label('Delete selected')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => self::forget($records)),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFailedJobs::route('/'),
        ];
    }

    /**
     * Pushes the jobs back onto their queue (queue:retry removes them from failed_jobs).
     *
     * @param \Illuminate\Support\Collection<int, FailedJob> $records
     */
    private static function retry(\Illuminate\Support\Collection $records): void
    {
        $uuids = $records->pluck('uuid')->all();
        Artisan::call('queue:retry', ['id' => $uuids]);
        AdminAudit::log('failed_job_retry', null, ['count' => count($uuids), 'jobs' => $records->map(fn (FailedJob $r) => class_basename($r->jobName()))->unique()->values()->all()]);
        Notification::make()->title(count($uuids) === 1 ? 'Job queued again' : count($uuids) . ' jobs queued again')->success()->send();
    }

    /**
     * @param \Illuminate\Support\Collection<int, FailedJob> $records
     */
    private static function forget(\Illuminate\Support\Collection $records): void
    {
        foreach ($records as $record) {
            Artisan::call('queue:forget', ['id' => $record->uuid]);
        }

        AdminAudit::log('failed_job_delete', null, ['count' => $records->count()]);
        Notification::make()->title($records->count() === 1 ? 'Failed job deleted' : $records->count() . ' failed jobs deleted')->success()->send();
    }
}
