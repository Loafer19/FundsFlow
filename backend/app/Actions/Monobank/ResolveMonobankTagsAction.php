<?php

namespace App\Actions\Monobank;

use App\Models\MonobankTagRule;
use App\Models\User;
use App\Services\Monobank\MccCategories;
use App\Services\Monobank\MerchantNormalizer;

class ResolveMonobankTagsAction
{
    /**
     * @return list<int>
     */
    public function execute(User $user, ?string $description, ?int $mcc, ?int $accountTagId): array
    {
        $ids = [];
        $key = MerchantNormalizer::normalize($description);

        if ($key !== null) {
            $rule = MonobankTagRule::query()
                ->where('user_id', $user->id)
                ->where('merchant_key', $key)
                ->first();

            if ($rule) {
                $ids[] = (int) $rule->tag_id;
            }
        }

        if ($ids === [] && $mcc) {
            $title = MccCategories::tagTitleFor($mcc);

            if ($title) {
                $tag = $user->tags()
                    ->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])
                    ->first();

                if ($tag) {
                    $ids[] = (int) $tag->id;
                }
            }
        }

        if ($accountTagId) {
            $ids[] = (int) $accountTagId;
        }

        return array_values(array_unique($ids));
    }
}
