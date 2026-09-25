<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\PlatformModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanModule>
 */
class PlanModuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(), 'module_key' => PlatformModule::factory(), 'is_enabled' => true,
        ];
    }
}
