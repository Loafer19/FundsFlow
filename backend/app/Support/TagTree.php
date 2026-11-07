<?php

namespace App\Support;

use App\Models\Tag;
use App\Models\User;

/**
 * A user's tag hierarchy. One rule everywhere (limits, goals, filters, per-tag totals): a tag
 * includes all of its descendants, and a transaction counts at most once per tag or selection.
 *
 * Load it with TagTree::for($user): one query per user per request/job (scoped cache, flushed
 * when a tag changes). Deleted tags are kept in the map so old transactions still roll up.
 */
final class TagTree
{
    /** @var array<int, list<int>> parent id => direct child ids */
    private array $children = [];

    /**
     * @param array<int, ?int> $parents tag id => parent id (null = top level)
     * @param array<int, array{emoji: string, title: string}> $meta tag id => emoji and title
     */
    public function __construct(private readonly array $parents, private readonly array $meta = [])
    {
        foreach ($parents as $id => $parentId) {
            if ($parentId !== null) {
                $this->children[$parentId][] = $id;
            }
        }
    }

    public static function for(User $user): self
    {
        return app(TagTreeCache::class)->get((int) $user->id);
    }

    public static function load(int $userId): self
    {
        $tags = Tag::withTrashed()
            ->where('user_id', $userId)
            ->get(['id', 'parent_id', 'emoji', 'title']);

        $parents = [];
        $meta = [];

        foreach ($tags as $tag) {
            $parents[(int) $tag->id] = $tag->parent_id === null ? null : (int) $tag->parent_id;
            $meta[(int) $tag->id] = ['emoji' => (string) $tag->emoji, 'title' => (string) $tag->title];
        }

        return new self($parents, $meta);
    }

    /**
     * The given ids plus all of their descendants, without duplicates.
     *
     * @param iterable<int|string> $ids
     * @return list<int>
     */
    public function expand(iterable $ids): array
    {
        $out = [];
        $queue = [];

        foreach ($ids as $id) {
            $queue[] = (int) $id;
        }

        while ($queue !== []) {
            $id = array_pop($queue);

            if (isset($out[$id])) {
                continue;
            }

            $out[$id] = true;

            foreach ($this->children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return array_keys($out);
    }

    /**
     * @return array{emoji: string, title: string}
     */
    public function meta(int $id): array
    {
        return $this->meta[$id] ?? ['emoji' => '', 'title' => ''];
    }

    /**
     * "emoji title" of a tag.
     */
    public function label(int $id): string
    {
        $meta = $this->meta($id);

        return trim($meta['emoji'] . ' ' . $meta['title']);
    }

    public function parentOf(int $id): ?int
    {
        return $this->parents[$id] ?? null;
    }

    /**
     * The top-level ancestor of a tag (the tag itself when it has no parent).
     */
    public function rootOf(int $id): int
    {
        $seen = [];

        while (($parent = $this->parents[$id] ?? null) !== null && !isset($seen[$parent])) {
            $seen[$id] = true;
            $id = $parent;
        }

        return $id;
    }

    /**
     * The tag and all of its ancestors (tag first).
     *
     * @return list<int>
     */
    public function selfAndAncestors(int $id): array
    {
        $out = [$id];

        while (($parent = $this->parents[$id] ?? null) !== null && !in_array($parent, $out, true)) {
            $out[] = $parent;
            $id = $parent;
        }

        return $out;
    }

    /**
     * Distinct top-level ancestors of the given tags: a transaction with several tags under one
     * parent counts once for that parent.
     *
     * @param iterable<int|string> $tagIds
     * @return list<int>
     */
    public function rootsOf(iterable $tagIds): array
    {
        $roots = [];

        foreach ($tagIds as $id) {
            $roots[$this->rootOf((int) $id)] = true;
        }

        return array_keys($roots);
    }
}
