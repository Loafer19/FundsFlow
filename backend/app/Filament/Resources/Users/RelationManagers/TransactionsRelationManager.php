<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransactionsRelationManager extends ReadOnlyRelationManager
{
    protected static string $relationship = 'transactions';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tags:id,title,emoji'))
            ->defaultSort('at', 'desc')
            ->columns([
                TextColumn::make('at')->label('Date')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('amount')->numeric(2)->sortable()
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('note')->limit(50)->searchable(),
                TextColumn::make('tags')
                    ->state(fn ($record): string => $record->tags->map(fn ($t) => trim($t->emoji . ' ' . $t->title))->implode(', '))
                    ->placeholder('—'),
                TextColumn::make('source')->badge(),
                TextColumn::make('created_at')->label('Added')->since()->sortable(),
            ])
            ->paginated([10, 25, 50]);
    }
}
