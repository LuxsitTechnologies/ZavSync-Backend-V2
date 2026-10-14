<?php

namespace Database\Factories;

use App\Models\BillingCheckoutAttempt;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingCheckoutAttempt>
 */
class BillingCheckoutAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'requested_by' => User::factory(),
            'idempotency_key' => $this->faker->unique()->uuid(),
            'payload_hash' => hash('sha256', 'billing-checkout-fixture'),
            'operation' => 'NEW_SUBSCRIPTION',
            'status' => 'PENDING',
            'currency' => 'PKR',
            'subtotal_minor' => 299_900,
            'total_minor' => 299_900,
            'price_snapshot' => [],
        ];
    }
}
