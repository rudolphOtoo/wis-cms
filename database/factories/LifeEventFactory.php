<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LifeEvent>
 */
class LifeEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'recorded_by_user_id' => User::factory(),
            'type' => fake()->randomElement(['death', 'birth']),
            'event_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function death(): static
    {
        return $this->state(fn () => [
            'type' => 'death',
            'member_id' => Member::factory(),
            'first_name' => null,
            'last_name' => null,
            'mother_first_name' => null,
            'mother_last_name' => null,
        ]);
    }

    public function birth(): static
    {
        return $this->state(fn () => [
            'type' => 'birth',
            'member_id' => null,
            'mother_first_name' => fake()->firstName(),
            'mother_last_name' => fake()->lastName(),
        ]);
    }
}
