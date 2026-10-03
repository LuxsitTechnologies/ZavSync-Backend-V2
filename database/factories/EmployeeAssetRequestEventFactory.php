<?php

namespace Database\Factories;

use App\Models\EmployeeAssetRequest;
use App\Models\EmployeeAssetRequestEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAssetRequestEvent>
 */
class EmployeeAssetRequestEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['employee_asset_request_id' => EmployeeAssetRequest::factory(),
            'company_id' => fn (array $attributes): string => EmployeeAssetRequest::query()->findOrFail($attributes['employee_asset_request_id'])->company_id,
            'event_type' => 'REQUESTED', 'request_version' => 1, 'actor_id' => User::factory(), 'occurred_at' => now()];
    }
}
