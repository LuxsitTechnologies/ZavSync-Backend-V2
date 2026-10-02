<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Models\Company;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollEntryReleaseService
{
    public function __construct(private readonly AuditService $audit) {}

    public function release(Request $request, string $companyId, string $entryId): PayrollEntry
    {
        return DB::transaction(function () use ($request, $companyId, $entryId): PayrollEntry {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $batchId = PayrollEntry::query()->where('company_id', $companyId)->findOrFail($entryId)->payroll_batch_id;
            $batch = PayrollBatch::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($batchId);
            $entry = PayrollEntry::query()->where('company_id', $companyId)->where('payroll_batch_id', $batch->id)->lockForUpdate()->findOrFail($entryId);

            if ($entry->released_at !== null) {
                return $entry;
            }
            if (! in_array($batch->status, ['POSTED', 'PARTIALLY_PAID', 'PAID'], true) || $batch->journal_id === null || $batch->posted_at === null) {
                throw new PayrollException('PAYROLL_NOT_POSTED', 'Only posted payroll may be released to an employee.');
            }

            $entry->forceFill(['released_at' => now(), 'released_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_payslip_released', 'payroll', $entry, null, ['released_at' => $entry->released_at->toIso8601String(), 'released_by' => $entry->released_by]);

            return $entry;
        }, 3);
    }
}
