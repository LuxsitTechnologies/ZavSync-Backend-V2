<?php

namespace Database\Factories;

use App\Models\BillingCataloguePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingCataloguePrice>
 */
class BillingCataloguePriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'addon',
            'code' => $this->faker->unique()->slug(2),
            'currency' => 'PKR',
            'billing_interval' => 'monthly',
            'version' => 1,
            'unit_amount_minor' => 100_000,
            'is_purchasable' => true,
        ];
    }
}
