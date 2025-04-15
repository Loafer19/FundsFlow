<?php

namespace Database\Factories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'at' => $this->faker->dateTimeBetween(now()->startOfYear(), now()->endOfYear()),
            'amount' => $this->faker->randomFloat(2, -800, 1250),
            'note' => $this->faker->optional()->sentence(),
        ];
    }
}
