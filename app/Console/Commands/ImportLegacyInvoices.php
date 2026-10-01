<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Migration\LegacyImportFileReader;
use App\Services\Migration\LegacyInvoiceImportService;
use App\Services\Platform\EntitlementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

#[Signature('legacy:invoice-import
    {path : JSON snapshot path inside the configured private import directory}
    {--company= : Target V2 company UUID}
    {--source-company= : Original V1 company ID}
    {--actor= : V2 user ID authorizing the operation}
    {--dry-run : Validate and reconcile without database writes}
    {--reconcile-only : Recompute a completed or interrupted import report without writes}
    {--resume= : Resume a failed import run UUID}')]
#[Description('Validate or import a company-scoped V1 invoice/FBR snapshot without accounting or provider side effects')]
class ImportLegacyInvoices extends Command
{
    public function handle(LegacyImportFileReader $reader, LegacyInvoiceImportService $importer): int
    {
        try {
            $companyId = $this->requiredOption('company');
            $sourceCompanyId = $this->requiredOption('source-company');
            $actorId = $this->requiredOption('actor');
            $company = Company::query()->findOrFail($companyId);
            $actor = User::query()->findOrFail($actorId);
            if (! $actor->is_platform_admin && (! $actor->belongsToCompany($company->id) || ! $actor->hasCompanyPermission($company->id, 'migration.manage'))) {
                throw ValidationException::withMessages(['actor' => 'The actor is not authorized to run legacy migration operations for this company.']);
            }
            $input = $reader->read((string) $this->argument('path'));
            app(EntitlementService::class)->enforceRequest($company->id, 'api/v1/pakistan-fbr/migrations');
            if ($this->option('reconcile-only') && ($this->option('dry-run') || $this->option('resume'))) {
                throw ValidationException::withMessages(['mode' => 'Reconciliation-only cannot be combined with dry-run or resume.']);
            }
            $report = $this->option('reconcile-only')
                ? $importer->report($input['data'], $company, $sourceCompanyId, $input['fingerprint'])
                : $importer->execute(
                    $input['data'],
                    $company,
                    $actor,
                    $sourceCompanyId,
                    $input['fingerprint'],
                    $input['filename'],
                    (bool) $this->option('dry-run'),
                    is_string($this->option('resume')) ? $this->option('resume') : null,
                );
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(json_encode(['status' => 'INVALID', 'errors' => $exception->errors()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::INVALID;
        } catch (Throwable) {
            $this->error(json_encode(['status' => 'FAILED', 'message' => 'The import failed. Review application logs and the migration exception ledger.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([$name => "The --{$name} option is required."]);
        }

        return $value;
    }
}
