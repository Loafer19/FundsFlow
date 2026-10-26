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
                TextColumn::make('title')->placeholder('—'),
                TextColumn::make('periods_count')->label('Periods')->numeric(),
                TextColumn::make('created_at')->date(),
            ]);
    }
}
