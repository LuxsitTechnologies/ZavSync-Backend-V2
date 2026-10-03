<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description');
            $table->string('priority', 12)->default('NORMAL');
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('attachment_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->char('attachment_request_key_hash', 64)->nullable();
            $table->char('attachment_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'created_by', 'create_request_key_hash'], 'announcements_create_key');
            $table->index(['company_id', 'status', 'published_at'], 'announcements_company_feed');
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'description' => 'Company announcement access.', 'created_at' => $now, 'updated_at' => $now],
            ['employee.announcements.view', 'announcements.view', 'announcements.manage', 'announcements.publish'],
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_announcements');
    }
};
