<?php

namespace Database\Factories;

use App\Models\FbrReferenceValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FbrReferenceValue>
 */
class FbrReferenceValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['category' => 'PROVINCE', 'code' => fake()->unique()->bothify('PROV-###'), 'label' => fake()->state(), 'parent_code' => null, 'metadata' => [], 'source' => 'LOCAL_FIXTURE', 'source_version' => '2026-01', 'is_active' => true, 'valid_from' => '2026-01-01', 'valid_until' => null];
    }
}
