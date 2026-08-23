<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DefaultTagsSeeder extends Seeder
{
    public function run(User $user): void
    {
        $tree = [
            [
                'title' => 'Income',
                'emoji' => '💰',
                'calc_balance' => false,
                'children' => [
                    ['title' => 'Salary', 'emoji' => '💼', 'calc_balance' => false],
                    ['title' => 'Freelance', 'emoji' => '🧑‍💻', 'calc_balance' => false],
                    ['title' => 'Investments', 'emoji' => '📈', 'calc_balance' => false],
                    ['title' => 'Gifts', 'emoji' => '🎁', 'calc_balance' => false],
                    ['title' => 'Other income', 'emoji' => '➕', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Food & dining',
                'emoji' => '🍔',
                'calc_balance' => true,
                'children' => [
                    ['title' => 'Groceries', 'emoji' => '🛒', 'calc_balance' => false],
                    ['title' => 'Restaurants', 'emoji' => '🍽️', 'calc_balance' => false],
                    ['title' => 'Coffee', 'emoji' => '☕', 'calc_balance' => false],
                    ['title' => 'Delivery', 'emoji' => '🛵', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Housing',
                'emoji' => '🏠',
                'calc_balance' => true,
                'children' => [
                    ['title' => 'Rent / mortgage', 'emoji' => '🔑', 'calc_balance' => false],
                    ['title' => 'Utilities', 'emoji' => '💡', 'calc_balance' => false],
                    ['title' => 'Internet', 'emoji' => '🌐', 'calc_balance' => false],
                    ['title' => 'Home maintenance', 'emoji' => '🔧', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Transport',
                'emoji' => '🚗',
                'calc_balance' => true,
                'children' => [
                    ['title' => 'Fuel', 'emoji' => '⛽', 'calc_balance' => false],
                    ['title' => 'Public transit', 'emoji' => '🚌', 'calc_balance' => false],
                    ['title' => 'Taxi / ride-hail', 'emoji' => '🚕', 'calc_balance' => false],
                    ['title' => 'Parking', 'emoji' => '🅿️', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Shopping',
                'emoji' => '🛍️',
                'calc_balance' => true,
                'children' => [
                    ['title' => 'Clothes', 'emoji' => '👕', 'calc_balance' => false],
                    ['title' => 'Electronics', 'emoji' => '📱', 'calc_balance' => false],
                    ['title' => 'Household', 'emoji' => '🧴', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Bills & subscriptions',
                'emoji' => '🧾',
                'calc_balance' => true,
                'children' => [
                    ['title' => 'Phone', 'emoji' => '📞', 'calc_balance' => false],
                    ['title' => 'Streaming', 'emoji' => '📺', 'calc_balance' => false],
                    ['title' => 'Software', 'emoji' => '💻', 'calc_balance' => false],
                    ['title' => 'Insurance', 'emoji' => '🛡️', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Entertainment',
                'emoji' => '🎉',
                'calc_balance' => true,
                'children' => [
                    ['title' => 'Movies & events', 'emoji' => '🎬', 'calc_balance' => false],
                    ['title' => 'Games', 'emoji' => '🎮', 'calc_balance' => false],
                    ['title' => 'Hobbies', 'emoji' => '🎨', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Health',
                'emoji' => '❤️',
                'calc_balance' => true,
                'children' => [
                    ['title' => 'Pharmacy', 'emoji' => '💊', 'calc_balance' => false],
                    ['title' => 'Doctor', 'emoji' => '🩺', 'calc_balance' => false],
                    ['title' => 'Fitness', 'emoji' => '🏋️', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Personal',
                'emoji' => '👤',
                'calc_balance' => true,
                'children' => [
                    ['title' => 'Education', 'emoji' => '📚', 'calc_balance' => false],
                    ['title' => 'Care', 'emoji' => '💇', 'calc_balance' => false],
                    ['title' => 'Pets', 'emoji' => '🐾', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Travel',
                'emoji' => '✈️',
                'calc_balance' => true,
                'children' => [
                    ['title' => 'Flights', 'emoji' => '🛫', 'calc_balance' => false],
                    ['title' => 'Hotels', 'emoji' => '🏨', 'calc_balance' => false],
                    ['title' => 'Local transport', 'emoji' => '🗺️', 'calc_balance' => false],
                ],
            ],
            [
                'title' => 'Other',
                'emoji' => '📦',
                'calc_balance' => false,
                'children' => [],
            ],
        ];

        foreach ($tree as $category) {
            $children = $category['children'] ?? [];
            unset($category['children']);

            $parent = $user->tags()->create($category);

            if ($children === []) {
                continue;
            }

            $user->tags()->createMany(
                array_map(
                    static fn (array $child) => [...$child, 'parent_id' => $parent->id],
                    $children,
                ),
            );
        }
    }
}
