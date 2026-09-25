<?php

namespace Tests\Feature;

use App\Jobs\GenerateCompanyExport;
use App\Models\AuditLog;
use App\Models\CompanyExport;
use App\Models\CompanyUser;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformAuditSecuritySystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_center_filters_immutable_company_history(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.audit.view']);
        $match = AuditLog::factory()->for($company)->for($user)->create(['module' => 'CRM', 'action' => 'updated']);
        AuditLog::factory()->for($company)->for($user)->create(['module' => 'Payroll']);
        AuditLog::factory()->create();

        $this->getJson('/api/v1/platform/audit?module=CRM&action=updated', ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($route) => str_contains($route->uri(), 'platform/audit') && in_array('PUT', $route->methods(), true)));
    }

    public function test_request_correlation_id_is_returned_and_used_by_audit(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.manage']);
        $correlationId = '5ae92d25-77c4-4dd7-9b75-3dd93acfac85';

        $this->putJson('/api/v1/platform/settings', ['legal_name' => 'Correlated Company'], ['X-Company-Id' => $company->id, 'X-Request-Id' => $correlationId])
            ->assertOk()->assertHeader('X-Request-Id', $correlationId);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'correlation_id' => $correlationId]);
    }

    public function test_security_center_only_lists_sessions_and_events_for_company_members(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.security.view']);
        $member = User::factory()->create();
        CompanyUser::factory()->for($company)->for($member)->create();
        $outsider = User::factory()->create();
        SecurityEvent::factory()->for($company)->for($member)->create();
        SecurityEvent::factory()->create(['user_id' => $outsider->id]);
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            ['id' => 'member-session', 'user_id' => $member->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Browser', 'payload' => '{}', 'last_activity' => now()->timestamp],
            ['id' => 'outsider-session', 'user_id' => $outsider->id, 'ip_address' => '127.0.0.2', 'user_agent' => 'Browser', 'payload' => '{}', 'last_activity' => now()->timestamp],
        ]);

        $this->getJson('/api/v1/platform/security', ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonCount(1, 'sessions')->assertJsonPath('sessions.0.id', 'member-session');
    }

    public function test_health_endpoints_report_liveness_and_dependency_readiness(): void
    {
        $this->getJson('/api/v1/health/live')->assertOk()->assertJsonPath('status', 'ok');
        $this->getJson('/api/v1/health/ready')->assertOk()->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', true)->assertJsonPath('checks.cache', true)->assertJsonPath('checks.storage', true);
    }

    public function test_company_admin_can_revoke_a_company_members_session(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.security.manage']);
        $member = User::factory()->create();
        CompanyUser::factory()->for($company)->for($member)->create();
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'revocable-session', 'user_id' => $member->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Browser', 'payload' => '{}', 'last_activity' => now()->timestamp]);

        $this->deleteJson('/api/v1/platform/security/sessions/revocable-session', [], ['X-Company-Id' => $company->id])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'revocable-session']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'session_revoked']);
    }

    public function test_failed_job_endpoint_omits_payload_and_requires_permission(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.jobs.view']);
        $user->forceFill(['is_platform_admin' => true])->save();
        DB::table('failed_jobs')->insert([
            'uuid' => '3f330bb1-b130-49c6-90ee-8deab7fc5e9b', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'SafeJob', 'secret' => 'must-not-leak']), 'exception' => "RuntimeException: failed\ntrace", 'failed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/platform/system/failed-jobs', ['X-Company-Id' => $company->id])->assertOk()->assertJsonPath('data.0.type', 'SafeJob');
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
    }

    public function test_failed_job_endpoint_requires_platform_administrator_status(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.jobs.view']);

        $this->getJson('/api/v1/platform/system/failed-jobs', ['X-Company-Id' => $company->id])->assertForbidden();
    }

    public function test_company_export_is_queued_and_cross_tenant_download_is_hidden(): void
    {
        Queue::fake([GenerateCompanyExport::class]);
        [, $company] = $this->actingAsCompanyUser(['platform.exports.manage', 'platform.exports.view']);
        $response = $this->postJson('/api/v1/platform/exports', ['sections' => ['settings', 'users', 'crm_leads']], ['X-Company-Id' => $company->id])->assertAccepted();
        Queue::assertPushed(GenerateCompanyExport::class);
        $foreign = CompanyExport::factory()->create(['status' => 'COMPLETED', 'storage_key' => 'foreign.json', 'expires_at' => now()->addDay()]);

        $this->getJson("/api/v1/platform/exports/{$foreign->id}/download", ['X-Company-Id' => $company->id])->assertNotFound();
        $this->assertDatabaseHas('company_exports', ['id' => $response->json('id'), 'company_id' => $company->id, 'status' => 'PENDING']);
    }

    public function test_export_job_is_idempotent_and_tenant_scoped(): void
    {
        Storage::fake('local');
        [$user, $company] = $this->actingAsCompanyUser([]);
        $export = CompanyExport::factory()->for($company)->create(['requested_by' => $user->id, 'sections' => ['users', 'accounting_reports']]);
        $job = new GenerateCompanyExport($export->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $export->refresh();
        $this->assertSame('COMPLETED', $export->status);
        Storage::disk('local')->assertExists($export->storage_key);
        $contents = Storage::disk('local')->get($export->storage_key);
        $this->assertStringContainsString($company->id, $contents);
        $this->assertStringContainsString('trial_balance', $contents);

        $this->assertStringNotContainsString('password', $contents);
    }
}
