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
        Schema::create('employee_asset_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id');
            $table->string('type', 30)->default('NEW_EQUIPMENT');
            $table->string('item_description', 1000);
            $table->text('reason');
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('version')->default(1);
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('create_request_key_hash', 64)->nullable();
            $table->char('create_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'employee_id'], 'asset_requests_employee_company_fk')
                ->references(['company_id', 'id'])->on('employees')->restrictOnDelete();
            $table->unique(['company_id', 'employee_id', 'create_request_key_hash'], 'asset_requests_create_key');
            $table->index(['company_id', 'employee_id', 'status'], 'asset_requests_employee_status');
        });
        Schema::create('employee_asset_request_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_asset_request_id')->constrained('employee_asset_requests', indexName: 'asset_request_events_request_fk')->cascadeOnDelete();
            $table->string('event_type', 30);
            $table->unsignedInteger('request_version');
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['employee_asset_request_id', 'request_version'], 'asset_request_events_version_uniq');
        });
        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'description' => 'Employee asset request access.', 'created_at' => $now, 'updated_at' => $now],
            ['employee.assets.view', 'employee.assets.request', 'assets.view', 'assets.decide'],
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_asset_request_events');
        Schema::dropIfExists('employee_asset_requests');
    }
};
