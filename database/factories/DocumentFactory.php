<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'uploaded_by' => User::factory(), 'documentable_type' => Customer::class,
            'documentable_id' => fake()->uuid(), 'category' => 'general', 'original_filename' => 'document.pdf',
            'storage_disk' => 'local', 'storage_key' => 'documents/'.fake()->uuid().'.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 1024, 'checksum_sha256' => hash('sha256', fake()->uuid()),
        ];
    }
}
