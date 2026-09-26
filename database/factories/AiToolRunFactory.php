<?php

namespace Database\Factories;

use App\Models\AiToolRun;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiToolRun>
 */
class AiToolRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'user_id' => User::factory(), 'tool_name' => 'accounting.trial_balance', 'required_permission' => 'accounting.view', 'status' => 'COMPLETED', 'input' => [], 'output' => []];
    }
}
