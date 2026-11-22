<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RestoreTagAction
{
    public function execute(User $user, Tag $tag): Tag
    {
        Gate::forUser($user)->authorize('delete', $tag);

        if ($tag->trashed()) {
            $tag->restore();
        }

        return $tag;
    }
}
