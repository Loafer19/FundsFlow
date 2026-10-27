<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BudgetsRelationManager extends ReadOnlyRelationManager
{
    protected static string $relationship = 'budgets';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('periods'))
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ($state?->value ?? $state) === 'goal' ? 'Goal' : 'Limit')
                    ->color(fn ($state): string => ($state?->value ?? $state) === 'goal' ? 'success' : 'gray'),
                TextColumn::make('title')->placeholder('—'),
                TextColumn::make('target_date')->label('Deadline')->date()->placeholder('—'),
                TextColumn::make('periods_count')->label('Periods')->numeric(),
                TextColumn::make('created_at')->date(),
            ]);
    }
}
