<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UpdateTagAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, Tag $tag, array $data): Tag
    {
        Gate::forUser($user)->authorize('update', $tag);

        $tag->update($data);

        return $tag;
    }
}
