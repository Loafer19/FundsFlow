<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;

/**
 * Match AI-suggested tag titles to the user's tags (case-insensitive exact title).
 * Shared by the Telegram receipt flow and the web assistant receipt flow.
 */
final class MatchTagTitlesAction
{
    public function __construct(
        private readonly ListTagsAction $listTags,
    ) {}

    /**
     * @param list<string>|mixed $titles
     * @return list<Tag>
     */
    public function execute(User $user, mixed $titles): array
    {
        if (!is_array($titles) || $titles === []) {
            return [];
        }

        $wanted = [];
        foreach ($titles as $title) {
            if (!is_string($title)) {
                continue;
            }
            $normalized = mb_strtolower(trim($title));
            if ($normalized !== '') {
                $wanted[$normalized] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $matched = [];
        foreach ($this->listTags->execute($user) as $tag) {
            $key = mb_strtolower(trim((string) $tag->title));
            if (isset($wanted[$key])) {
                $matched[] = $tag;
                unset($wanted[$key]);
            }
        }

        return $matched;
    }

    /**
     * @param list<string>|mixed $titles
     * @return list<int>
     */
    public function ids(User $user, mixed $titles): array
    {
        return array_map(static fn (Tag $tag): int => (int) $tag->id, $this->execute($user, $titles));
    }
}
