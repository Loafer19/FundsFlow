<?php

namespace App\Support;

/**
 * Request/job-scoped cache of TagTree per user (bound as scoped in AppServiceProvider).
 * Tag model events forget a user's entry, so a tag created mid-request is seen right away.
 */
final class TagTreeCache
{
    /** @var array<int, TagTree> */
    private array $trees = [];

    public function get(int $userId): TagTree
    {
        return $this->trees[$userId] ??= TagTree::load($userId);
    }

    public function forget(int $userId): void
    {
        unset($this->trees[$userId]);
    }
}
