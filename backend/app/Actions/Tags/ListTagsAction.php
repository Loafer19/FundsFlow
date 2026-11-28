<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class ListTagsAction
{
    /**
     * @param bool $withCounts Add transactions_count to each tag (one grouped subquery; for the tag manager).
     * @return Collection<int, Tag>
     */
    public function execute(User $user, bool $withArchived = false, bool $withCounts = false): Collection
    {
        Gate::forUser($user)->authorize('viewAny', Tag::class);

        $query = $user->tags();

        if ($withArchived) {
            $query->withTrashed();
        }

        if ($withCounts) {
            $query->withCount('transactions');
        }

        return $query->get();
    }
}
