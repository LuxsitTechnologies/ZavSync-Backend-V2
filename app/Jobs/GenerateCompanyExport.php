<?php

namespace App\Jobs;

use App\Models\CompanyExport;
use App\Services\Accounting\FinancialReportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateCompanyExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly string $exportId) {}

    public function uniqueId(): string
    {
        return $this->exportId;
    }

    /**
     * Execute the job.
     */
    public function handle(FinancialReportService $reports): void
    {
        $export = CompanyExport::query()->findOrFail($this->exportId);
        if ($export->status === 'COMPLETED') {
            return;
        }
        $export->update(['status' => 'PROCESSING']);
        $companyId = $export->company_id;
        $allowed = [
            'settings' => ['company_settings', ['company_id']],
            'users' => ['company_users', ['company_id']],
            'customers' => ['customers', ['company_id']],
            'suppliers' => ['suppliers', ['company_id']],
            'invoices' => ['invoices', ['company_id']],
            'employees' => ['employees', ['company_id']],
            'crm_accounts' => ['crm_accounts', ['company_id']],
            'crm_contacts' => ['crm_contacts', ['company_id']],
            'crm_leads' => ['crm_leads', ['company_id']],
            'crm_deals' => ['crm_deals', ['company_id']],
        ];
        $payload = ['generated_at' => now()->toIso8601String(), 'company_id' => $companyId, 'sections' => []];
        foreach ($export->sections as $section) {
            if ($section === 'accounting_reports') {
                $asOf = now()->toDateString();
                $payload['sections'][$section] = [
                    'trial_balance' => $reports->trialBalance($companyId, null, $asOf),
                    'profit_and_loss' => $reports->profitAndLoss($companyId, null, $asOf),
                    'balance_sheet' => $reports->balanceSheet($companyId, $asOf),
                ];

                continue;
            }
            if (isset($allowed[$section])) {
                [$table] = $allowed[$section];
                $payload['sections'][$section] = DB::table($table)->where('company_id', $companyId)->orderBy('created_at')->get()->all();
            }
        }
        $path = "companies/{$companyId}/exports/{$export->id}.json";
        Storage::disk($export->storage_disk)->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $export->update(['status' => 'COMPLETED', 'storage_key' => $path, 'completed_at' => now(), 'expires_at' => now()->addDays(7)]);
    }

    public function failed(Throwable $exception): void
    {
        CompanyExport::query()->whereKey($this->exportId)->update(['status' => 'FAILED', 'failure_message' => 'Export generation failed. Review the correlated queue failure.']);
    }
}
