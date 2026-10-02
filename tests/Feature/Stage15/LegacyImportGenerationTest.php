<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Models\Company;
use App\Models\LegacyEntityMap;
use App\Models\LegacyImportRun;
use App\Models\User;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\TestCase;

class LegacyImportGenerationTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, RefreshDatabase;

    public function test_normal_import_and_completed_replay_keep_generation_one(): void
    {
        [$company, $user] = $this->context();
        $first = $this->execute($company, $user);
        $before = LegacyImportRun::query()->sole()->getAttributes();
        $repeat = $this->execute($company, $user);

        $this->assertSame($first['run_id'], $repeat['run_id']);
        $this->assertFalse($repeat['writes_performed']);
        $this->assertSame(1, LegacyImportRun::query()->sole()->execution_generation);
        $this->assertSame('COMPLETED', LegacyImportRun::query()->sole()->status);
        $this->assertSame($before, LegacyImportRun::query()->sole()->getAttributes());
        $this->assertDatabaseCount('pakistan_fbr_invoices', 1);
        $this->assertDatabaseCount('legacy_entity_maps', 4);
        $this->firewall();
    }

    public function test_start_acquisition_rolls_back_completely(): void
    {
        [$company, $user] = $this->context();
        Event::listen('eloquent.created: '.LegacyImportRun::class, static function (): void {
            throw new \RuntimeException('Injected acquisition rollback');
        });
        try {
            $this->execute($company, $user);
            $this->fail('Acquisition must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected acquisition rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('legacy_import_runs', 0);
        $this->assertDatabaseCount('legacy_entity_maps', 0);
        $this->firewall();
    }

    public function test_resume_acquisition_rolls_back_generation_and_state(): void
    {
        [$company, $user] = $this->context();
        $run = $this->failedRun($company, $user);
        $before = $run->fresh()->getAttributes();
        Event::listen('eloquent.updated: '.LegacyImportRun::class, static function (): void {
            throw new \RuntimeException('Injected acquisition rollback');
        });
        try {
            $this->execute($company, $user, $run->id);
            $this->fail('Acquisition must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected acquisition rollback', $exception->getMessage());
        }
        $this->assertSame($before, $run->fresh()->getAttributes());
        $this->assertDatabaseCount('legacy_entity_maps', 0);
        $this->firewall();
    }

    public function test_source_company_mapping_failure_rolls_back_mapping_without_partial_evidence(): void
    {
        [$company, $user] = $this->context();
        Event::listen('eloquent.created: '.LegacyEntityMap::class, static function (LegacyEntityMap $map): void {
            if ($map->source_entity_type === 'company') {
                throw new \RuntimeException('Injected mapping rollback');
            }
        });
        try {
            $this->execute($company, $user);
            $this->fail('Mapping must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected mapping rollback', $exception->getMessage());
        }
        $this->assertSame('FAILED', LegacyImportRun::query()->sole()->status);
        $this->assertSame(1, LegacyImportRun::query()->sole()->execution_generation);
        $this->assertDatabaseCount('legacy_entity_maps', 0);
        $this->assertDatabaseCount('pakistan_fbr_invoices', 0);
        $this->assertDatabaseCount('legacy_fbr_evidence', 0);
        $this->firewall();
    }

    public function test_exhausted_generation_cannot_wrap_or_mutate_run(): void
    {
        [$company, $user] = $this->context();
        $run = $this->failedRun($company, $user);
        $run->forceFill(['execution_generation' => PHP_INT_MAX])->save();
        $before = $run->fresh()->getAttributes();
        try {
            $this->execute($company, $user, $run->id);
            $this->fail('Overflow must fail closed.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('exhausted', $exception->getMessage());
        }
        $this->assertSame($before, $run->fresh()->getAttributes());
        $this->firewall();
    }

    public function test_other_company_cannot_resume_or_advance_generation(): void
    {
        [$company, $user] = $this->context();
        $run = $this->failedRun($company, $user);
        $before = $run->fresh()->getAttributes();
        try {
            $this->execute(Company::factory()->create(), $user, $run->id);
            $this->fail('Other company must not resolve this run.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $run->fresh()->getAttributes());
        }
        $this->firewall();
    }

    /** @return array{Company, User} */
    private function context(): array
    {
        Http::preventStrayRequests();
        $this->mock(FbrGateway::class)->shouldNotReceive('submit');

        return [Company::factory()->create(), User::factory()->create()];
    }

    private function failedRun(Company $company, User $user): LegacyImportRun
    {
        $run = LegacyImportRun::factory()->for($company)->create([
            'created_by' => $user->id, 'status' => 'FAILED', 'source_fingerprint' => hash('sha256', 'generation'),
        ]);
        $run->forceFill(['execution_generation' => 1])->save();

        return $run;
    }

    /** @return array<string, mixed> */
    private function execute(Company $company, User $user, ?string $resume = null): array
    {
        return app(LegacyInvoiceImportService::class)->execute($this->legacySnapshot(), $company, $user, '4', hash('sha256', 'generation'), 'synthetic.json', resumeRunId: $resume);
    }

    private function firewall(): void
    {
        foreach (['invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_transactions', 'pakistan_fbr_submission_attempts', 'fbr_submission_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }
}
