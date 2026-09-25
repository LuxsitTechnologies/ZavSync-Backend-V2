<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->unique()->words(3, true), 'category' => 'GENERAL', 'subject' => 'Hello {{contact.first_name}}', 'body_text' => 'Hello {{contact.first_name}}, from {{company.name}}.', 'allowed_variables' => ['contact.first_name', 'company.name'], 'is_active' => true, 'created_by' => User::factory()];
    }
}
