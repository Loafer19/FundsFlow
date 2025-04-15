<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = $this->createOrUpdateAdminUser();
        $this->clearUserData($user);

        $tags = $this->createTagsForUser($user);
        $transactions = $this->createTransactionsForUser($user);

        $this->attachTagsToTransactions($transactions, $tags);
    }

    private function createOrUpdateAdminUser(): User
    {
        return User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'John Doe',
                'password' => bcrypt('password'),
            ]
        );
    }

    private function clearUserData(User $user): void
    {
        Tag::where('user_id', $user->id)->delete();
        Transaction::where('user_id', $user->id)->delete();
    }

    private function createTagsForUser(User $user): Collection
    {
        $grandparents = Tag::factory()
            ->count(rand(3, 5))
            ->create(['user_id' => $user->id]);

        $parents = Tag::factory()
            ->count(rand(4, 6))
            ->sequence(fn () => [
                'user_id' => $user->id,
                'parent_id' => $grandparents->random()->id,
            ])
            ->create();

        $children = Tag::factory()
            ->count(rand(2, 4))
            ->sequence(fn () => [
                'user_id' => $user->id,
                'parent_id' => $parents->random()->id,
            ])
            ->create();

        return $grandparents->merge($parents)->merge($children);
    }

    private function createTransactionsForUser(User $user): Collection
    {
        return Transaction::factory()
            ->count(rand(700, 1500))
            ->create(['user_id' => $user->id]);
    }

    private function attachTagsToTransactions($transactions, $tags): void
    {
        $transactions->each(function (Transaction $transaction) use ($tags) {
            $transaction->tags()->attach(
                $tags->random(rand(0, 3))?->pluck('id') ?? []
            );
        });
    }
}
