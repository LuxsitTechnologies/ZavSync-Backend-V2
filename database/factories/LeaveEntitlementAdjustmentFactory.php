<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\LeaveEntitlement;
use App\Models\LeaveEntitlementAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveEntitlementAdjustment>
 */
class LeaveEntitlementAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(),
            'leave_entitlement_id' => fn (array $attributes): string => LeaveEntitlement::factory()->for(Company::query()->findOrFail($attributes['company_id']))->create()->id,
            'delta_units' => 2, 'reason' => 'Additional annual allocation',
            'request_key_hash' => hash('sha256', fake()->uuid()), 'payload_hash' => hash('sha256', fake()->uuid()),
            'created_by' => User::factory(), 'created_at' => now()];
    }
}
