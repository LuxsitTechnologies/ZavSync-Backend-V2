<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmPipeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmPipeline>
 */
class CrmPipelineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(), 'is_active' => true, 'is_default' => false,
            'created_by' => User::factory(), 'updated_by' => null,
        ];
    }
}
