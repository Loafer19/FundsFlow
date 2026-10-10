<?php

namespace App\Support;

use App\Models\Tag;
use App\Models\User;

final class TagIds
{
    /**
     * Owned tag ids to store on a record: live tags, plus deleted tags the
     * record already carries. A deleted tag can't be attached anew, but an
     * edit of an old record must neither fail nor silently drop it.
     *
     * @param iterable<int|string> $requested
     * @param iterable<int|string> $alreadyAttached
     * @return list<int>
     */
    public static function forSync(User $user, iterable $requested, iterable $alreadyAttached = []): array
    {
        $requested = collect($requested)->map(fn ($id) => (int) $id)->unique()->values();

        if ($requested->isEmpty()) {
            return [];
        }

        $attached = collect($alreadyAttached)->map(fn ($id) => (int) $id)->all();

        return $user->tags()
            ->withTrashed()
            ->whereIn('id', $requested)
            ->get(['id', 'deleted_at'])
            ->filter(fn (Tag $tag) => !$tag->trashed() || in_array((int) $tag->id, $attached, true))
            ->map(fn (Tag $tag) => (int) $tag->id)
            ->values()
            ->all();
    }
}
