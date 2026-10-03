<?php

namespace Tests\Feature\Stage16;

use App\Models\AttendanceBreak;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceRevision;
use App\Models\AttendanceSession;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Journal;
use App\Models\PayrollEntry;
use App\Models\Permission;
use App\Models\PlatformModule;
use App\Models\Role;
use App\Services\Platform\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_clock_break_and_out_use_server_seconds_and_replay_once(): void
    {
        [$company] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T04:00:00Z'));
        $first = $this->postJson('/api/v1/employee/attendance/clock-in', [], $this->headers($company, 'in-1'))
            ->assertOk()->assertJsonPath('state', 'CLOCKED_IN');
        $this->assertSame($first->json(), $this->postJson('/api/v1/employee/attendance/clock-in', [], $this->headers($company, 'in-1'))->assertOk()->json());
        $this->postJson('/api/v1/employee/attendance/clock-in', [], $this->headers($company, 'in-2'))->assertConflict();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T05:00:00Z'));
        $this->postJson('/api/v1/employee/attendance/breaks/start', [], $this->headers($company, 'break-1'))->assertOk()->assertJsonPath('state', 'ON_BREAK');
        $this->postJson('/api/v1/employee/attendance/clock-out', [], $this->headers($company, 'out-bad'))->assertConflict();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T05:10:00Z'));
        $this->postJson('/api/v1/employee/attendance/breaks/end', [], $this->headers($company, 'end-1'))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T06:00:00Z'));
        $this->postJson('/api/v1/employee/attendance/breaks/start', [], $this->headers($company, 'break-2'))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T06:05:00Z'));
        $this->postJson('/api/v1/employee/attendance/breaks/end', [], $this->headers($company, 'end-2'))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T07:00:00Z'));
        $result = $this->postJson('/api/v1/employee/attendance/clock-out', [], $this->headers($company, 'out-1'))
            ->assertOk()->assertJsonPath('state', 'CLOCKED_OUT')->assertJsonPath('effective.break_seconds', 900)
            ->assertJsonPath('effective.worked_seconds', 9900);
        $this->assertSame($result->json(), $this->postJson('/api/v1/employee/attendance/clock-out', [], $this->headers($company, 'out-1'))->assertOk()->json());
        $this->getJson('/api/v1/employee/attendance/status', $this->headers($company))
            ->assertOk()->assertJsonPath('state', 'CLOCKED_OUT')->assertJsonPath('session.id', $result->json('id'))
            ->assertJsonPath('allowed_actions', ['CLOCK_IN']);
        $this->assertDatabaseCount('attendance_sessions', 1);
        $this->assertDatabaseCount('attendance_breaks', 2);
        $this->assertSame(0, PayrollEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_overnight_work_date_and_timezone_snapshot_survive_company_change(): void
    {
        [$company] = $this->context();
        $company->update(['timezone' => 'America/New_York']);
        $this->travelTo(CarbonImmutable::parse('2026-11-01T03:30:00Z'));
        $this->postJson('/api/v1/employee/attendance/clock-in', [], $this->headers($company, 'in'))
            ->assertOk()->assertJsonPath('work_date', '2026-10-31')->assertJsonPath('timezone', 'America/New_York');
        $company->update(['timezone' => 'Asia/Karachi']);
        $this->travelTo(CarbonImmutable::parse('2026-11-01T11:30:00Z'));
        $this->getJson('/api/v1/employee/attendance/status', $this->headers($company))
            ->assertOk()->assertJsonPath('session.work_date', '2026-10-31');
        $this->postJson('/api/v1/employee/attendance/clock-out', [], $this->headers($company, 'out'))
            ->assertOk()->assertJsonPath('effective.worked_seconds', 28800);
        $this->getJson('/api/v1/employee/attendance/calendar?month=2026-10', $this->headers($company))
            ->assertOk()->assertJsonPath('days.0.date', '2026-10-31');
    }

    public function test_correction_approval_keeps_original_evidence_and_decides_once(): void
    {
        [$company] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T04:00:00Z'));
        $sessionId = $this->postJson('/api/v1/employee/attendance/clock-in', [], $this->headers($company, 'in'))->assertOk()->json('id');
        $this->travelTo(CarbonImmutable::parse('2026-09-01T12:00:00Z'));
        $this->postJson('/api/v1/employee/attendance/clock-out', [], $this->headers($company, 'out'))->assertOk();
        $payload = ['clock_in_at' => '2026-09-01T04:00:00Z', 'clock_out_at' => '2026-09-01T12:30:00Z', 'breaks' => [], 'reason' => 'Forgot to clock out on time.'];
        $correctionId = $this->postJson("/api/v1/employee/attendance/{$sessionId}/corrections", $payload, $this->headers($company, 'correct'))
            ->assertCreated()->assertJsonPath('status', 'PENDING')->json('id');
        $this->postJson("/api/v1/attendance/corrections/{$correctionId}/approve", ['reason' => 'Evidence reviewed.'], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->getJson("/api/v1/employee/attendance/{$sessionId}", $this->headers($company))
            ->assertOk()->assertJsonPath('original.worked_seconds', 28800)->assertJsonPath('effective.worked_seconds', 30600);
        $this->postJson("/api/v1/attendance/corrections/{$correctionId}/reject", ['reason' => 'Cannot reject now.'], $this->headers($company))->assertConflict();
        $this->assertDatabaseCount('attendance_revisions', 1);
        $this->assertSame('2026-09-01 12:00:00', AttendanceSession::query()->findOrFail($sessionId)->clock_out_at->format('Y-m-d H:i:s'));
    }

    public function test_unlinked_and_former_employee_boundaries_are_enforced(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.attendance.view', 'employee.attendance.clock']);
        $this->getJson('/api/v1/employee/attendance/status', $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'EMPLOYEE_IDENTITY_NOT_LINKED');
        $employee = Employee::factory()->for($company)->create(['created_by' => $user->id, 'status' => 'resigned']);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail()
            ->forceFill(['employee_id' => $employee->id])->save();
        $this->getJson('/api/v1/employee/attendance/status', $this->headers($company))->assertOk();
        $this->postJson('/api/v1/employee/attendance/clock-in', [], $this->headers($company, 'former'))->assertForbidden();
    }

    public function test_former_employee_can_request_a_historical_correction_but_cannot_clock(): void
    {
        [$company, $employee] = $this->context();
        $session = AttendanceSession::factory()->for($company)->for($employee)->create(['created_by' => auth()->id()]);
        $employee->update(['status' => 'terminated']);
        $payload = ['clock_in_at' => '2026-09-01T04:00:00Z', 'clock_out_at' => '2026-09-01T12:30:00Z',
            'breaks' => [], 'reason' => 'Historical clock-out time needs review.'];

        $this->getJson('/api/v1/employee/attendance/'.$session->id, $this->headers($company))
            ->assertOk()->assertJsonPath('id', $session->id);
        $this->getJson('/api/v1/employee/attendance/status', $this->headers($company))
            ->assertOk()->assertJsonPath('allowed_actions', []);
        $created = $this->postJson('/api/v1/employee/attendance/'.$session->id.'/corrections', $payload, $this->headers($company, 'former-correction'))
            ->assertCreated()->assertJsonPath('status', 'PENDING');
        $this->postJson('/api/v1/employee/attendance/'.$session->id.'/corrections', $payload, $this->headers($company, 'former-correction'))
            ->assertOk()->assertJsonPath('id', $created->json('id'));
        $this->postJson('/api/v1/employee/attendance/'.$session->id.'/corrections',
            [...$payload, 'clock_out_at' => '2026-09-01T13:00:00Z'], $this->headers($company, 'former-correction'))
            ->assertConflict()->assertJsonPath('error_code', 'IDEMPOTENCY_PAYLOAD_CONFLICT');
        $this->postJson('/api/v1/employee/attendance/clock-in', [], $this->headers($company, 'former-clock'))
            ->assertForbidden()->assertJsonPath('error_code', 'EMPLOYEE_ATTENDANCE_INACTIVE');
        $this->assertDatabaseCount('attendance_correction_requests', 1);
        $this->assertDatabaseCount('attendance_revisions', 0);
    }

    public function test_permissions_and_payroll_entitlement_do_not_leak_across_domains(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['payroll.view', 'employee.payroll.view']);
        $employee = Employee::factory()->for($company)->create(['created_by' => $user->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail()
            ->forceFill(['employee_id' => $employee->id])->save();

        $this->getJson('/api/v1/employee/attendance/status', $this->headers($company))->assertForbidden();
        $this->getJson('/api/v1/attendance/sessions', $this->headers($company))->assertForbidden();
        $this->postJson('/api/v1/employee/attendance/clock-in', [], $this->headers($company, 'none'))->assertForbidden();
        $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();
        foreach (['employee.attendance.view', 'employee.attendance.clock'] as $permission) {
            $membership->role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission]));
        }
        $this->getJson('/api/v1/employee/attendance/status', $this->headers($company))->assertOk();
        PlatformModule::query()->firstOrCreate(['key' => 'payroll'], ['name' => 'Payroll']);
        CompanyEntitlement::factory()->for($company)->create(['module_key' => 'payroll', 'is_enabled' => false, 'updated_by' => $user->id]);
        app(EntitlementService::class)->forget($company->id);
        $this->getJson('/api/v1/employee/attendance/status', $this->headers($company))
            ->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
    }

    public function test_invalid_transitions_and_client_supplied_timestamp_do_not_mutate(): void
    {
        [$company] = $this->context();
        $this->postJson('/api/v1/employee/attendance/clock-out', [], $this->headers($company, 'out'))->assertConflict();
        $this->postJson('/api/v1/employee/attendance/breaks/start', [], $this->headers($company, 'start'))->assertConflict();
        $this->postJson('/api/v1/employee/attendance/clock-in', ['clock_in_at' => '2000-01-01T00:00:00Z'], $this->headers($company, 'bad'))
            ->assertUnprocessable()->assertJsonPath('error_code', 'ATTENDANCE_PAYLOAD_INVALID');
        $this->postJson('/api/v1/employee/attendance/clock-in', [], $this->headers($company, 'shared'))->assertOk();
        $this->postJson('/api/v1/employee/attendance/breaks/start', [], $this->headers($company, 'shared'))
            ->assertConflict()->assertJsonPath('error_code', 'IDEMPOTENCY_PAYLOAD_CONFLICT');
        $this->postJson('/api/v1/employee/attendance/breaks/end', [], $this->headers($company, 'no-break'))->assertConflict();
        $this->assertDatabaseCount('attendance_sessions', 1);
        $this->assertDatabaseCount('attendance_breaks', 0);

        $this->travelTo(CarbonImmutable::now('UTC')->addMinute());
        $this->postJson('/api/v1/employee/attendance/breaks/start', [], $this->headers($company, 'same-second-start'))->assertOk();
        $this->postJson('/api/v1/employee/attendance/breaks/end', [], $this->headers($company, 'same-second-end'))
            ->assertConflict()->assertJsonPath('error_code', 'ATTENDANCE_DURATION_INVALID');
        $this->assertSame('ON_BREAK', AttendanceSession::query()->sole()->state);
        $this->assertSame(1, AttendanceBreak::query()->whereNotNull('active_session_id')->count());
    }

    public function test_history_and_admin_detail_hide_cross_company_sessions(): void
    {
        [$company, $employee] = $this->context();
        $session = AttendanceSession::factory()->for($company)->for($employee)->create(['created_by' => auth()->id()]);
        $otherCompany = Company::factory()->create();
        $otherEmployee = Employee::factory()->for($otherCompany)->create();
        $foreignSession = AttendanceSession::factory()->for($otherCompany)->for($otherEmployee)->create();

        $this->getJson('/api/v1/employee/attendance/history?from=2026-09-01&to=2026-09-30&per_page=1', $this->headers($company))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $session->id);
        $this->getJson('/api/v1/employee/attendance/'.$foreignSession->id, $this->headers($company))->assertNotFound();
        $this->getJson('/api/v1/attendance/sessions/'.$foreignSession->id, $this->headers($company))->assertNotFound();
        $this->getJson('/api/v1/employee/attendance/history?from=2024-01-01&to=2026-09-30', $this->headers($company))
            ->assertUnprocessable()->assertJsonPath('error_code', 'ATTENDANCE_RANGE_TOO_LARGE');
        $content = $this->getJson('/api/v1/employee/attendance/'.$session->id, $this->headers($company))->assertOk()->getContent();
        $this->assertStringNotContainsString('base_salary', $content);
        $this->assertStringNotContainsString('payment_financial_account_id', $content);
    }

    public function test_admin_intervention_is_a_revision_and_cannot_overwrite_original_punch(): void
    {
        [$company, $employee] = $this->context();
        $session = AttendanceSession::factory()->for($company)->for($employee)->create(['created_by' => auth()->id()]);
        $payload = ['clock_in_at' => '2026-09-01T04:00:00Z', 'clock_out_at' => '2026-09-01T13:00:00Z', 'breaks' => [], 'reason' => 'Manager reviewed missed clock-out.'];

        $this->postJson('/api/v1/attendance/sessions/'.$session->id.'/interventions', $payload, $this->headers($company, 'admin-1'))
            ->assertCreated()->assertJsonPath('status', 'APPROVED');
        $this->getJson('/api/v1/attendance/sessions/'.$session->id, $this->headers($company))
            ->assertOk()->assertJsonPath('original.worked_seconds', 28800)->assertJsonPath('effective.worked_seconds', 32400);
        $this->assertSame('2026-09-01 12:00:00', $session->fresh()->clock_out_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('attendance_revisions', 1);
        $this->assertSame(0, PayrollEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_attendance_factories_create_company_consistent_evidence(): void
    {
        $session = AttendanceSession::factory()->create();
        $break = AttendanceBreak::factory()->create(['attendance_session_id' => $session->id]);
        $correction = AttendanceCorrectionRequest::factory()->create(['attendance_session_id' => $session->id]);
        $revision = AttendanceRevision::factory()->create(['attendance_correction_request_id' => $correction->id]);

        $this->assertSame($session->company_id, $break->company_id);
        $this->assertSame($session->company_id, $correction->company_id);
        $this->assertSame($session->company_id, $revision->company_id);
        $this->assertSame($session->id, $revision->attendance_session_id);
    }

    public function test_stale_correction_cannot_override_a_newer_administrative_revision(): void
    {
        [$company, $employee] = $this->context();
        $session = AttendanceSession::factory()->for($company)->for($employee)->create(['created_by' => auth()->id()]);
        $request = ['clock_in_at' => '2026-09-01T04:00:00Z', 'clock_out_at' => '2026-09-01T12:30:00Z',
            'breaks' => [], 'reason' => 'Employee reports a missing punch.'];
        $pendingId = $this->postJson('/api/v1/employee/attendance/'.$session->id.'/corrections', $request, $this->headers($company, 'pending'))
            ->assertCreated()->json('id');
        $intervention = [...$request, 'clock_out_at' => '2026-09-01T13:00:00Z', 'reason' => 'Manager reviewed original evidence.'];
        $this->postJson('/api/v1/attendance/sessions/'.$session->id.'/interventions', $intervention, $this->headers($company, 'intervene'))
            ->assertCreated();

        $this->postJson('/api/v1/attendance/corrections/'.$pendingId.'/approve', ['reason' => 'Approve old request.'], $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'ATTENDANCE_CORRECTION_STALE');
        $this->getJson('/api/v1/employee/attendance/'.$session->id, $this->headers($company))
            ->assertOk()->assertJsonPath('effective.worked_seconds', 32400);
        $this->assertSame('PENDING', AttendanceCorrectionRequest::query()->findOrFail($pendingId)->status);
        $this->assertDatabaseCount('attendance_revisions', 1);
    }

    public function test_correction_validates_explicit_offset_break_order_and_same_work_date(): void
    {
        [$company, $employee] = $this->context();
        $session = AttendanceSession::factory()->for($company)->for($employee)->create(['created_by' => auth()->id()]);
        $base = ['clock_in_at' => '2026-09-01T04:00:00Z', 'clock_out_at' => '2026-09-01T12:00:00Z',
            'breaks' => [], 'reason' => 'Review the original punch times.'];

        $this->postJson('/api/v1/employee/attendance/'.$session->id.'/corrections',
            [...$base, 'clock_in_at' => '2026-09-01T04:00:00'], $this->headers($company, 'no-zone'))
            ->assertUnprocessable()->assertJsonPath('error_code', 'ATTENDANCE_TIMEZONE_REQUIRED');
        $this->postJson('/api/v1/employee/attendance/'.$session->id.'/corrections',
            [...$base, 'clock_in_at' => '2026-08-31T04:00:00Z'], $this->headers($company, 'wrong-day'))
            ->assertUnprocessable()->assertJsonPath('error_code', 'ATTENDANCE_CORRECTION_INVALID');
        $this->postJson('/api/v1/employee/attendance/'.$session->id.'/corrections',
            [...$base, 'breaks' => [['started_at' => '2026-09-01T05:00:00Z', 'ended_at' => '2026-09-01T06:00:00Z'],
                ['started_at' => '2026-09-01T05:30:00Z', 'ended_at' => '2026-09-01T06:30:00Z']]], $this->headers($company, 'overlap'))
            ->assertUnprocessable()->assertJsonPath('error_code', 'ATTENDANCE_CORRECTION_INVALID');
        $this->assertDatabaseCount('attendance_correction_requests', 0);
    }

    public function test_company_switching_never_reuses_employee_identity_or_history(): void
    {
        [$companyA, $employeeA] = $this->context();
        $user = auth()->user();
        $companyB = Company::factory()->create();
        $employeeB = Employee::factory()->for($companyB)->create(['created_by' => $user->id]);
        $roleB = Role::query()->create(['company_id' => $companyB->id, 'name' => 'Employee B']);
        foreach (['employee.attendance.view', 'employee.attendance.clock'] as $permission) {
            $roleB->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission]));
        }
        CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'role_id' => $roleB->id,
            'is_active' => true])->forceFill(['employee_id' => $employeeB->id])->save();
        $sessionA = AttendanceSession::factory()->for($companyA)->for($employeeA)->create(['created_by' => $user->id]);
        $sessionB = AttendanceSession::factory()->for($companyB)->for($employeeB)->create(['created_by' => $user->id]);

        $this->getJson('/api/v1/employee/attendance/history?from=2026-09-01&to=2026-09-30', $this->headers($companyA))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $sessionA->id);
        $this->getJson('/api/v1/employee/attendance/history?from=2026-09-01&to=2026-09-30', $this->headers($companyB))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $sessionB->id);
        $this->getJson('/api/v1/employee/attendance/'.$sessionA->id, $this->headers($companyB))->assertNotFound();
    }

    public function test_database_rejects_two_active_sessions_or_open_breaks(): void
    {
        [$company, $employee] = $this->context();
        $first = AttendanceSession::factory()->for($company)->for($employee)->open()->create(['created_by' => auth()->id()]);
        try {
            AttendanceSession::factory()->for($company)->for($employee)->open()->create(['created_by' => auth()->id()]);
            $this->fail('Active-session uniqueness must reject the second session.');
        } catch (QueryException) {
            $this->assertDatabaseCount('attendance_sessions', 1);
        }
        AttendanceBreak::factory()->for($first, 'session')->create(['company_id' => $company->id, 'active_session_id' => $first->id,
            'ended_at' => null, 'duration_seconds' => null]);
        try {
            AttendanceBreak::factory()->for($first, 'session')->create(['company_id' => $company->id, 'active_session_id' => $first->id,
                'ended_at' => null, 'duration_seconds' => null]);
            $this->fail('Open-break uniqueness must reject the second break.');
        } catch (QueryException) {
            $this->assertDatabaseCount('attendance_breaks', 1);
        }
    }

    /** @return array{Company, Employee} */
    private function context(): array
    {
        [$user, $company] = $this->actingAsCompanyUser([
            'employee.attendance.view', 'employee.attendance.clock', 'employee.attendance.correction.request',
            'attendance.view', 'attendance.manage', 'attendance.corrections.manage',
        ]);
        $employee = Employee::factory()->for($company)->create(['created_by' => $user->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail()
            ->forceFill(['employee_id' => $employee->id])->save();

        return [$company, $employee];
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
