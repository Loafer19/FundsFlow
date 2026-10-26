<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\BudgetsRelationManager;
use App\Filament\Resources\Users\RelationManagers\RecurringTransactionsRelationManager;
use App\Filament\Resources\Users\RelationManagers\TagsRelationManager;
use App\Filament\Resources\Users\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of accounts + account-level actions (see UserAdminActions).
 * Nothing here creates, edits or deletes users or their financial data.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'email';

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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['identities' => fn ($q) => $q->select('id', 'user_id', 'provider', 'external_id', 'meta')])
            ->withCount('transactions')
            ->withMax('tokens', 'last_used_at');
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
            TagsRelationManager::class,
            BudgetsRelationManager::class,
            RecurringTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }

    /** Telegram identity summary, e.g. "@name" or "id 123". */
    public static function telegramLabel(User $user): ?string
    {
        $identity = $user->identities->firstWhere('provider', 'telegram');

        if (!$identity) {
            return null;
        }

        $username = $identity->meta['username'] ?? null;

        return $username ? '@' . $username : 'id ' . $identity->external_id;
    }
}
