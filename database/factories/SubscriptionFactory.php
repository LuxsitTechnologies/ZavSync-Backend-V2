<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'plan_id' => Plan::factory(), 'status' => 'ACTIVE',
            'billing_interval' => 'monthly', 'starts_at' => now(), 'renews_at' => now()->addMonth(),
        ];
    }
}
