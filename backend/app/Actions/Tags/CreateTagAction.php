<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CreateTagAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data): Tag
    {
        Gate::forUser($user)->authorize('create', Tag::class);

        return $user->tags()->create($data);
    }
}
