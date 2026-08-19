<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MoMoNetwork;
use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'member_id' => null,
            'payment_type' => PaymentType::Offering,
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'GHS',
            'channel' => PaymentChannel::MobileMoney,
            'momo_network' => MoMoNetwork::MTN,
            'momo_number' => '0'.fake()->numerify('244123456'),
            'status' => PaymentStatus::Pending,
            'reference' => 'WIS-'.now()->timestamp.'-'.strtoupper(fake()->bothify('########')),
            'metadata' => null,
            'gateway_response' => null,
            'recorded_by_user_id' => null,
            'paid_at' => null,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Success,
            'gateway_reference' => fake()->bothify(' ##########'),
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Failed,
            'gateway_response' => ['message' => 'Transaction failed'],
        ]);
    }

    public function withMember(): static
    {
        return $this->state(fn () => [
            'member_id' => Member::factory(),
            'recorded_by_user_id' => User::factory(),
        ]);
    }
}
