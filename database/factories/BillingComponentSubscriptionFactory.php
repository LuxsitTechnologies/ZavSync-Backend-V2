<?php

namespace Database\Factories;

use App\Models\BillingCataloguePrice;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\Company;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingComponentSubscription>
 */
class BillingComponentSubscriptionFactory extends Factory
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
            'catalogue_price_id' => BillingCataloguePrice::factory()->state(['code' => 'fbr']),
            'checkout_attempt_id' => fn (array $attributes): string => BillingCheckoutAttempt::factory()
                ->create(['company_id' => $attributes['company_id'], 'operation' => 'COMPONENT_CHECKOUT', 'status' => 'ACTIVE'])->id,
            'kind' => 'addon', 'code' => 'fbr', 'currency' => 'PKR', 'billing_interval' => 'monthly',
            'quantity' => 1, 'status' => 'ACTIVE',
            'provider_subscription_id' => 'sub_'.$this->faker->unique()->bothify('############'),
            'paid_through_at' => now()->addMonth(),
        ];
    }
}
