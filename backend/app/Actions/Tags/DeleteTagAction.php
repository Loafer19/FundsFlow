<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DeleteTagAction
{
    public function execute(User $user, Tag $tag): void
    {
        Gate::forUser($user)->authorize('delete', $tag);

        $tag->delete();
    }
}
