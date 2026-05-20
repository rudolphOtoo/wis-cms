<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class VisitorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '024'.fake()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'visit_date' => now(),
            'follow_up_status' => 'pending',
        ];
    }
}
