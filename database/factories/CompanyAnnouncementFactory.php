<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyAnnouncement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyAnnouncement>
 */
class CompanyAnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'title' => fake()->sentence(5),
            'description' => fake()->paragraph(), 'priority' => 'NORMAL', 'status' => 'DRAFT',
            'version' => 1, 'created_by' => User::factory()];
    }
}
