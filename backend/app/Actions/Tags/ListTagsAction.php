<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class ListTagsAction
{
    /**
     * @return Collection<int, Tag>
     */
    public function execute(User $user): Collection
    {
        Gate::forUser($user)->authorize('viewAny', Tag::class);

        return $user->tags()->get();
    }
}
