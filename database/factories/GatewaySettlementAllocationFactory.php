<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\GatewaySettlement;
use App\Models\GatewaySettlementAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GatewaySettlementAllocation>
 */
class GatewaySettlementAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'gateway_settlement_id' => GatewaySettlement::factory(), 'source_type' => 'customer_payment', 'source_id' => CustomerPayment::factory(), 'amount' => 1000];
    }
}
