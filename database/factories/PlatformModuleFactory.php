<?php

namespace Database\Factories;

use App\Models\PlatformModule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformModule>
 */
class PlatformModuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => Str::lower(fake()->unique()->word()), 'name' => fake()->unique()->words(2, true), 'is_active' => true,
        ];
    }
}
