<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;

/**
 * Admins look, never touch: no create/edit/delete/associate actions on user data.
 * The panel itself is admin-only (User::canAccessPanel), so model policies are skipped.
 */
abstract class ReadOnlyRelationManager extends RelationManager
{
    protected static bool $shouldSkipAuthorization = true;

    // Render the active tab with the page instead of on scroll-into-view.
    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return true;
    }
}
