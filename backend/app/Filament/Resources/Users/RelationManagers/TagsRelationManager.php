<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TagsRelationManager extends ReadOnlyRelationManager
{
    protected static string $relationship = 'tags';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent:id,title'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('emoji'),
                TextColumn::make('title')->searchable(),
                TextColumn::make('parent.title')->label('Parent')->placeholder('—'),
                IconColumn::make('calc_balance')->label('Balance')->boolean(),
            ]);
    }
}
