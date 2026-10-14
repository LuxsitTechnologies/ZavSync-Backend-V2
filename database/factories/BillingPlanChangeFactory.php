<?php

namespace Database\Factories;

use App\Models\BillingCataloguePrice;
use App\Models\BillingPlanChange;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingPlanChange>
 */
class BillingPlanChangeFactory extends Factory
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
            'subscription_id' => fn (array $attributes): string => Subscription::factory()
                ->create(['company_id' => $attributes['company_id']])->id,
            'source_catalogue_price_id' => BillingCataloguePrice::factory(),
            'target_catalogue_price_id' => BillingCataloguePrice::factory(),
            'idempotency_key' => $this->faker->unique()->uuid(),
            'payload_hash' => hash('sha256', 'plan-change-fixture'),
            'operation' => 'UPGRADE', 'status' => 'PAYMENT_PENDING',
            'source_plan_code' => 'starter', 'target_plan_code' => 'professional',
            'target_billing_interval' => 'monthly', 'currency' => 'PKR',
            'target_amount_minor' => 999_900, 'previous_paid_through_at' => now()->addMonth(),
            'requested_by' => User::factory(),
        ];
    }
}
