<?php
namespace Database\Factories;

use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id'        => Branch::factory(),
            'category_id'      => FinanceCategory::factory(),
            'type'             => 'income',
            'amount'           => fake()->randomFloat(2, 10, 1000),
            'currency'         => 'GHS',
            'transaction_date' => now(),
            'recorded_by'      => User::factory(),
        ];
    }

    public function expense(): static
    {
        return $this->state(fn() => ['type' => 'expense']);
    }
}
