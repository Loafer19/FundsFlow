<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecurringTransactionsRelationManager extends ReadOnlyRelationManager
{
    protected static string $relationship = 'recurringTransactions';

    protected static ?string $title = 'Recurring';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('amount')->numeric(2),
                TextColumn::make('note')->limit(40)->placeholder('—'),
                TextColumn::make('frequency')->badge(),
                TextColumn::make('next_run_at')->date(),
                TextColumn::make('ends_at')->date()->placeholder('—'),
                IconColumn::make('active')->boolean(),
            ]);
    }
}
