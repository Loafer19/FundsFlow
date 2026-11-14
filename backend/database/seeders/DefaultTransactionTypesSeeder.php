<?php

namespace Database\Seeders;

use App\Actions\TransactionTypes\SaveTransactionTypeAction;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Starter transaction types for a new account (User::created, after DefaultTagsSeeder), whatever the signup
 * channel. Existing accounts are not backfilled. TYPES is the single source of the defaults.
 */
class DefaultTransactionTypesSeeder extends Seeder
{
    /**
     * 'tag' = title of a default tag to apply when the account has one (DefaultTagsSeeder names).
     *
     * @var list<array{name: string, emoji: string, color: string, tag: ?string, fields: list<array<string, mixed>>}>
     */
    public const TYPES = [
        [
            'name' => 'Fuel',
            'emoji' => '⛽',
            'color' => '#f59e0b',
            'tag' => 'Transport',
            'fields' => [
                ['key' => 'liters', 'label' => 'Liters', 'type' => 'number', 'unit' => 'l'],
                ['key' => 'price_per_liter', 'label' => 'Price per liter', 'type' => 'number', 'unit' => '₴'],
                ['key' => 'fuel', 'label' => 'Fuel', 'type' => 'select', 'options' => ['A-95', 'A-95+', 'Diesel', 'LPG']],
                ['key' => 'station', 'label' => 'Station', 'type' => 'text'],
                ['key' => 'total', 'label' => 'Total', 'type' => 'computed', 'formula' => 'liters * price_per_liter', 'fill_amount' => true, 'unit' => '₴'],
            ],
        ],
        [
            'name' => 'Groceries',
            'emoji' => '🛒',
            'color' => '#22c55e',
            'tag' => null,
            'fields' => [
                ['key' => 'store', 'label' => 'Store', 'type' => 'text'],
                ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['Big shop', 'Quick buy']],
            ],
        ],
        [
            'name' => 'Subscription',
            'emoji' => '🔄',
            'color' => '#6366f1',
            'tag' => 'Bills & subscriptions',
            'fields' => [
                ['key' => 'service', 'label' => 'Service', 'type' => 'text'],
                ['key' => 'period', 'label' => 'Period', 'type' => 'select', 'options' => ['Monthly', 'Yearly']],
                ['key' => 'next_charge', 'label' => 'Next charge', 'type' => 'date'],
            ],
        ],
        [
            'name' => 'Restaurant',
            'emoji' => '🍽',
            'color' => '#ec4899',
            'tag' => null,
            'fields' => [
                ['key' => 'place', 'label' => 'Place', 'type' => 'text'],
                ['key' => 'format', 'label' => 'Format', 'type' => 'select', 'options' => ['Cafe', 'Restaurant', 'Delivery']],
                ['key' => 'tip', 'label' => 'Tip', 'type' => 'number', 'unit' => '₴'],
            ],
        ],
    ];

    public function run(User $user): void
    {
        $save = app(SaveTransactionTypeAction::class);

        foreach (self::TYPES as $type) {
            $tagId = $type['tag'] === null ? null : $user->tags()->where('title', $type['tag'])->value('id');

            $save->execute($user, [
                'name' => $type['name'],
                'emoji' => $type['emoji'],
                'color' => $type['color'],
                'fields' => $type['fields'],
                'default_tag_ids' => $tagId ? [(int) $tagId] : [],
            ]);
        }
    }
}
