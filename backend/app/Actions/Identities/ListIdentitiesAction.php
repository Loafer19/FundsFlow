<?php

namespace App\Actions\Identities;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ListIdentitiesAction
{
    /**
     * @return Collection<int, Identity>
     */
    public function execute(User $user): Collection
    {
        return $user->identities()->get();
    }
}
