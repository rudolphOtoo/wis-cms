<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Wesleyan International Society',
            'location' => fake()->city(),
            'address' => fake()->address(),
            'phone' => '024'.fake()->numerify('#######'),
            'email' => fake()->unique()->companyEmail(),
            'is_active' => true,
        ];
    }
}
