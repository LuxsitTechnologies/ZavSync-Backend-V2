<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('code', 30);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('type', 30)->default('business');
            $table->string('ntn', 7)->nullable();
            $table->string('cnic', 13)->nullable();
            $table->string('strn', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country', 2)->default('PK');
            $table->string('postal_code', 20)->nullable();
            $table->string('contact_person')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->unsignedBigInteger('credit_limit')->nullable();
            $table->char('currency', 3)->default('PKR');
            $table->json('tax_metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'sequence']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
