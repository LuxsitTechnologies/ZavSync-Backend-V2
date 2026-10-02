<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, array{int, bool}>> */
    public const IDENTIFIERS = [
        'journals' => ['idempotency_key' => [100, true]],
        'invoices' => ['invoice_number' => [40, false], 'fbr_reference_number' => [255, true], 'creation_idempotency_key' => [100, true]],
        'fbr_submission_attempts' => ['idempotency_key' => [100, false], 'reference_number' => [255, true]],
        'customer_payments' => ['idempotency_key' => [100, false]],
        'purchase_orders' => ['creation_idempotency_key' => [100, false]],
        'purchase_receipts' => ['idempotency_key' => [100, false]],
        'supplier_bills' => ['supplier_invoice_number' => [100, false], 'creation_idempotency_key' => [100, false]],
        'supplier_payments' => ['idempotency_key' => [100, false]],
        'inventory_transactions' => ['idempotency_key' => [120, false]],
        'bank_statement_imports' => ['idempotency_key' => [120, false]],
        'bank_transactions' => ['origin_idempotency_key' => [120, true]],
        'internal_transfers' => ['idempotency_key' => [120, false]],
        'gateway_settlements' => ['idempotency_key' => [120, false]],
        'bank_reconciliations' => ['idempotency_key' => [120, false]],
        'bank_reconciliation_matches' => ['idempotency_key' => [120, false]],
        'accounting_close_records' => ['idempotency_key' => [255, true]],
        'payroll_batches' => ['posting_idempotency_key' => [120, true]],
        'payroll_payments' => ['idempotency_key' => [120, false]],
        'payroll_liability_settlements' => ['idempotency_key' => [120, false]],
        'crm_leads' => ['conversion_idempotency_key' => [100, true]],
        'crm_imports' => ['idempotency_key' => [100, true]],
        'crm_accounts' => ['customer_handoff_key' => [100, true]],
        'crm_deals' => ['customer_handoff_key' => [100, true]],
        'outreach_enrollments' => ['idempotency_key' => [255, false]],
        'outreach_send_attempts' => ['idempotency_key' => [255, false]],
        'knowledge_ingestion_runs' => ['idempotency_key' => [120, false]],
        'ai_messages' => ['idempotency_key' => [120, true]],
        'ai_action_proposals' => ['idempotency_key' => [120, false]],
        'ai_action_executions' => ['idempotency_key' => [120, false]],
        'intelligence_scenarios' => ['idempotency_key' => [120, false]],
        'scheduled_intelligence_runs' => ['idempotency_key' => [120, false]],
        'legacy_import_runs' => ['source_system' => [60, false], 'source_company_id' => [120, false]],
        'legacy_entity_maps' => ['source_system' => [60, false], 'source_id' => [120, false]],
        'migration_exceptions' => ['source_id' => [120, true]],
        'pakistan_fbr_invoices' => ['legacy_source_system' => [60, true], 'legacy_source_id' => [120, true], 'legacy_original_company_id' => [120, true], 'invoice_number' => [120, false], 'fbr_reference_number' => [255, true], 'creation_idempotency_key' => [100, true]],
        'pakistan_fbr_invoice_lines' => ['legacy_source_id' => [120, true]],
        'pakistan_fbr_submission_attempts' => ['idempotency_key' => [100, false], 'reference_number' => [255, true]],
        'legacy_fbr_evidence' => ['source_system' => [60, false], 'source_id' => [120, false], 'fbr_reference_number' => [255, true]],
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Exact identifier migration requires a certified database driver.');
        }
        if (DB::selectOne("SHOW COLLATION WHERE Collation = 'utf8mb4_nopad_bin'") === null) {
            throw new RuntimeException('Exact identifiers require utf8mb4_nopad_bin; certify this engine before migrating.');
        }
        foreach (self::IDENTIFIERS as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $name => [$length, $nullable]) {
                    $table->string($name, $length)->nullable($nullable)->charset('utf8mb4')->collation('utf8mb4_nopad_bin')->change();
                }
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Exact identifier identity cannot be safely collapsed by rollback. Use a reviewed forward migration.');
        }
    }
};
