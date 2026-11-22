<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Soft-delete a tag and its descendants (archive). */
class DeleteTagAction
{
    public function execute(User $user, Tag $tag): void
    {
        Gate::forUser($user)->authorize('delete', $tag);

        $this->deleteWithChildren($tag);
    }

    private function deleteWithChildren(Tag $tag): void
    {
        foreach ($tag->children as $child) {
            $this->deleteWithChildren($child);
        }

        $tag->delete();
    }
}
