<?php

namespace Database\Factories;

use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmPipelineStage>
 */
class CrmPipelineStageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_id' => CrmPipeline::factory(),
            'company_id' => fn (array $attributes): string => (string) CrmPipeline::query()->findOrFail($attributes['pipeline_id'])->company_id,
            'name' => fake()->unique()->word(), 'position' => fake()->unique()->numberBetween(1, 1000),
            'probability_bps' => 2500, 'is_won' => false, 'is_lost' => false, 'is_active' => true,
        ];
    }

    public function won(): static
    {
        return $this->state(fn (): array => ['probability_bps' => 10000, 'is_won' => true, 'is_lost' => false]);
    }

    public function lost(): static
    {
        return $this->state(fn (): array => ['probability_bps' => 0, 'is_won' => false, 'is_lost' => true]);
    }
}
