<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Journal>
 */
class JournalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'sequence' => fake()->unique()->numberBetween(1, 100000), 'number' => 'JV-2026-'.fake()->unique()->numerify('####'), 'posting_date' => '2026-09-22', 'reference' => null, 'reference_type' => 'manual_journal', 'source_id' => null, 'source' => 'manual', 'description' => fake()->sentence(), 'status' => 'draft', 'created_by' => User::factory(), 'posted_by' => null, 'posted_at' => null];
    }
}
