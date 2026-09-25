<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
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
            'user_id' => User::factory(),
            'action' => 'updated',
            'module' => 'platform',
            'entity_type' => Company::class,
            'entity_id' => fake()->uuid(),
            'old_values' => ['status' => 'old'],
            'new_values' => ['status' => 'new'],
            'ip_address' => fake()->ipv4(),
            'correlation_id' => fake()->uuid(),
        ];
    }
}
