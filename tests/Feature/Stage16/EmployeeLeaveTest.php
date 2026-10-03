<?php

namespace Tests\Feature\Stage16;

use App\Models\Company;
use App\Models\CompanyHoliday;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Journal;
use App\Models\LeaveEntitlement;
use App\Models\LeaveEntitlementAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestEvent;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeLeaveTest extends TestCase
{
    use RefreshDatabase;

    private User $employeeUser;

    private ?User $adminUser = null;

    public function test_request_reserves_exact_calendar_units_and_approval_does_not_create_accounting(): void
    {
        [$company, $employee, $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $payload = ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-07', 'day_portion' => 'FULL_DAY', 'reason' => 'Annual rest period'];
        $created = $this->postJson('/api/v1/employee/leave/requests', $payload, $this->headers($company, 'leave-1'))
            ->assertCreated()->assertJsonPath('units', 6)->assertJsonPath('status', 'PENDING');
        $id = $created->json('id');
        $this->postJson('/api/v1/employee/leave/requests', $payload, $this->headers($company, 'leave-1'))->assertOk()->assertJsonPath('id', $id);
        $this->getJson('/api/v1/employee/leave/summary?year=2026', $this->headers($company))
            ->assertOk()->assertJsonPath('data.0.pending_units', 6)->assertJsonPath('data.0.available_units', 14);
        $this->assertDatabaseCount('leave_requests', 1);
        $this->assertDatabaseCount('leave_request_events', 1);
        $this->assertSame(0, Journal::query()->count());
        foreach (['attendance_sessions', 'payroll_entries', 'invoices', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($employee->id, LeaveRequest::query()->findOrFail($id)->employee_id);
    }

    public function test_half_day_slots_overlap_only_when_the_same_slot_is_reserved(): void
    {
        [$company, , $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $payload = ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'day_portion' => 'FIRST_HALF', 'reason' => 'Morning appointment'];
        $this->postJson('/api/v1/employee/leave/requests', $payload, $this->headers($company, 'first'))->assertCreated()->assertJsonPath('units', 1);
        $this->postJson('/api/v1/employee/leave/requests', [...$payload, 'day_portion' => 'SECOND_HALF'], $this->headers($company, 'second'))->assertCreated();
        $this->postJson('/api/v1/employee/leave/requests', $payload, $this->headers($company, 'duplicate'))->assertConflict();
        $this->postJson('/api/v1/employee/leave/requests', [...$payload, 'end_date' => '2026-10-06'], $this->headers($company, 'range'))->assertUnprocessable();
        $this->assertDatabaseCount('leave_requests', 2);
    }

    public function test_past_dates_former_employee_and_wrong_idempotency_payload_are_rejected(): void
    {
        [$company, $employee, $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-03T00:00:00Z'));
        $payload = ['leave_type_id' => $type->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'day_portion' => 'FULL_DAY', 'reason' => 'Old leave request'];
        $this->postJson('/api/v1/employee/leave/requests', $payload, $this->headers($company, 'past'))->assertUnprocessable()->assertJsonPath('error_code', 'LEAVE_PAST_DATE');
        $employee->update(['status' => 'terminated']);
        $this->getJson('/api/v1/employee/leave/summary', $this->headers($company))->assertOk();
        $this->postJson('/api/v1/employee/leave/requests', [...$payload, 'start_date' => '2026-10-10', 'end_date' => '2026-10-10'], $this->headers($company, 'former'))->assertForbidden();
    }

    public function test_pending_employee_cancellation_is_evidenced_and_releases_balance(): void
    {
        [$company, , $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $id = $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'day_portion' => 'FULL_DAY', 'reason' => 'Annual rest period'], $this->headers($company, 'cancel'))->assertCreated()->json('id');
        $this->postJson("/api/v1/employee/leave/requests/{$id}/cancel", [], $this->headers($company))->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson("/api/v1/employee/leave/requests/{$id}/cancel", [], $this->headers($company))->assertOk();
        $this->getJson('/api/v1/employee/leave/summary?year=2026', $this->headers($company))->assertJsonPath('data.0.available_units', 20);
        $this->assertSame(['SUBMITTED', 'CANCEL_PENDING'], LeaveRequestEvent::query()->orderBy('created_at')->get()->pluck('action')->all());
    }

    public function test_admin_approval_and_future_cancellation_require_separate_decisions(): void
    {
        [$company, , $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $id = $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'day_portion' => 'FULL_DAY', 'reason' => 'Annual rest period'], $this->headers($company, 'approval'))->assertCreated()->json('id');
        $this->postJson("/api/v1/leave/requests/{$id}/approve", [], $this->headers($company))->assertForbidden()->assertJsonPath('error_code', 'LEAVE_SELF_APPROVAL_DENIED');
        $this->admin($company);
        $this->postJson("/api/v1/leave/requests/{$id}/approve", [], $this->headers($company))->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson("/api/v1/leave/requests/{$id}/approve", [], $this->headers($company))->assertOk();
        $this->assertDatabaseCount('leave_request_events', 2);
        $this->getJson('/api/v1/leave/entitlements?employee_id='.LeaveRequest::query()->findOrFail($id)->employee_id.'&year=2026', $this->headers($company))
            ->assertOk()->assertJsonPath('data.0.approved_units', 4)->assertJsonPath('data.0.available_units', 16);
        $this->employee($company);
        $this->postJson("/api/v1/employee/leave/requests/{$id}/cancel", [], $this->headers($company))->assertOk()->assertJsonPath('status', 'CANCELLATION_PENDING');
        $this->getJson('/api/v1/employee/leave/summary?year=2026', $this->headers($company))->assertJsonPath('data.0.available_units', 16);
        $this->admin($company);
        $this->postJson("/api/v1/leave/requests/{$id}/approve-cancellation", [], $this->headers($company))->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->employee($company);
        $this->postJson("/api/v1/employee/leave/requests/{$id}/cancel", [], $this->headers($company))->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->admin($company);
        $this->getJson('/api/v1/leave/entitlements?employee_id='.LeaveRequest::query()->findOrFail($id)->employee_id.'&year=2026', $this->headers($company))
            ->assertJsonPath('data.0.available_units', 20);
        $this->assertSame(0, Journal::query()->count());
        foreach (['attendance_sessions', 'payroll_entries', 'invoices', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_adjustment_is_exact_idempotent_and_cannot_overconsume(): void
    {
        [$company, $employee] = $this->context();
        $entitlement = LeaveEntitlement::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->admin($company);
        $url = "/api/v1/leave/entitlements/{$entitlement->id}/adjustments";
        $payload = ['delta_units' => -4, 'reason' => 'Correcting annual allocation'];
        $id = $this->postJson($url, $payload, $this->headers($company, 'adjust-1'))->assertCreated()->assertJsonPath('available_units', 16)->json('id');
        $this->postJson($url, $payload, $this->headers($company, 'adjust-1'))->assertOk()->assertJsonPath('id', $id);
        $this->postJson($url, [...$payload, 'delta_units' => -6], $this->headers($company, 'adjust-1'))->assertConflict();
        $this->postJson($url, [...$payload, 'delta_units' => -20], $this->headers($company, 'adjust-2'))->assertConflict();
        $this->assertDatabaseCount('leave_entitlement_adjustments', 1);
    }

    public function test_holidays_are_company_scoped_and_do_not_change_leave_units(): void
    {
        [$company, , $type] = $this->context();
        $this->admin($company);
        $this->postJson('/api/v1/leave/holidays', ['name' => 'Foundation Day', 'date' => '2026-10-05'], $this->headers($company))
            ->assertCreated()->assertJsonPath('date', '2026-10-05');
        $this->employee($company);
        $this->getJson('/api/v1/employee/leave/holidays?from=2026-10-01&to=2026-10-10', $this->headers($company))->assertOk()->assertJsonCount(1, 'data');
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'day_portion' => 'FULL_DAY', 'reason' => 'Leave on company holiday'], $this->headers($company, 'holiday-leave'))->assertCreated()->assertJsonPath('units', 2);
    }

    public function test_private_evidence_is_owner_scoped_and_general_documents_cannot_expose_it(): void
    {
        [$company, , $type] = $this->context();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $id = $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'day_portion' => 'FULL_DAY', 'reason' => 'Medical appointment'], $this->headers($company, 'medical'))->assertCreated()->json('id');
        $file = UploadedFile::fake()->create('note.pdf', 10, 'application/pdf');
        $document = $this->postJson("/api/v1/employee/leave/requests/{$id}/attachments", ['file' => $file], $this->headers($company))->assertCreated()->json('id');
        $this->getJson("/api/v1/employee/leave/requests/{$id}/attachments", $this->headers($company))->assertOk()->assertJsonCount(1, 'data');
        $this->get("/api/v1/employee/leave/requests/{$id}/attachments/{$document}", $this->headers($company))->assertOk();
        $this->admin($company);
        $this->getJson("/api/v1/leave/requests/{$id}/attachments", $this->headers($company))->assertOk()->assertJsonCount(1, 'data');
        $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', auth()->id())->firstOrFail();
        foreach (['platform.documents.view', 'platform.documents.manage'] as $permissionName) {
            $membership->role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permissionName]));
        }
        $this->getJson('/api/v1/platform/documents', $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/platform/documents/{$document}/download", $this->headers($company))->assertNotFound();
        $this->deleteJson("/api/v1/platform/documents/{$document}", [], $this->headers($company))->assertNotFound();
    }

    public function test_rejection_releases_reservation_and_requires_reason(): void
    {
        [$company, , $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $id = $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'day_portion' => 'FULL_DAY', 'reason' => 'Annual rest period'], $this->headers($company, 'reject'))->assertCreated()->json('id');
        $this->admin($company);
        $this->postJson("/api/v1/leave/requests/{$id}/reject", [], $this->headers($company))->assertUnprocessable();
        $this->postJson("/api/v1/leave/requests/{$id}/reject", ['reason' => 'Insufficient staffing'], $this->headers($company))->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->postJson("/api/v1/leave/requests/{$id}/reject", ['reason' => 'Insufficient staffing'], $this->headers($company))->assertOk();
        $this->assertDatabaseCount('leave_request_events', 2);
    }

    public function test_another_employee_cannot_read_or_cancel_a_request_or_its_private_evidence(): void
    {
        [$company, , $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $id = $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'day_portion' => 'FULL_DAY', 'reason' => 'Annual rest period'], $this->headers($company, 'private'))->assertCreated()->json('id');
        $other = User::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Other employee']);
        foreach (['employee.leave.view', 'employee.leave.cancel'] as $permissionName) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permissionName]));
        }
        $otherEmployee = Employee::factory()->for($company)->create(['created_by' => $other->id]);
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $other->id, 'role_id' => $role->id, 'is_active' => true])
            ->forceFill(['employee_id' => $otherEmployee->id])->save();
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/employee/leave/requests/{$id}", $this->headers($company))->assertNotFound();
        $this->postJson("/api/v1/employee/leave/requests/{$id}/cancel", [], $this->headers($company))->assertNotFound();
        $this->getJson("/api/v1/employee/leave/requests/{$id}/attachments", $this->headers($company))->assertNotFound();
        $this->getJson('/api/v1/employee/leave/requests', $this->headers($company))->assertJsonCount(0, 'data');
    }

    public function test_payroll_or_attendance_permission_is_not_leave_authority(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.payroll.view', 'employee.attendance.view', 'payroll.view']);
        $employee = Employee::factory()->for($company)->create(['created_by' => $user->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail()->forceFill(['employee_id' => $employee->id])->save();
        $this->getJson('/api/v1/employee/leave/summary', $this->headers($company))->assertForbidden();
        $this->getJson('/api/v1/leave/requests', $this->headers($company))->assertForbidden();
        $this->getJson('/api/v1/employee/leave/holidays', $this->headers($company))->assertForbidden();
    }

    public function test_leave_factories_respect_tenant_foreign_keys(): void
    {
        $holiday = CompanyHoliday::factory()->create();
        $entitlement = LeaveEntitlement::factory()->create();
        $adjustment = LeaveEntitlementAdjustment::factory()->create();
        $request = LeaveRequest::factory()->create();
        $event = LeaveRequestEvent::factory()->create();
        $this->assertNotNull($holiday->company);
        $this->assertSame($entitlement->company_id, $entitlement->employee->company_id);
        $this->assertSame($entitlement->company_id, $entitlement->type->company_id);
        $this->assertSame($adjustment->company_id, $adjustment->entitlement->company_id);
        $this->assertSame($request->company_id, $request->employee->company_id);
        $this->assertSame($request->company_id, $request->type->company_id);
        $this->assertSame($event->company_id, $event->request->company_id);
    }

    public function test_unpaid_leave_does_not_require_or_consume_entitlement(): void
    {
        [$company] = $this->context();
        $type = LeaveType::query()->create(['company_id' => $company->id, 'name' => 'Unpaid Leave', 'is_paid' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $id = $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'day_portion' => 'FULL_DAY', 'reason' => 'Unpaid personal leave'], $this->headers($company, 'unpaid'))->assertCreated()->assertJsonPath('units', 4)->json('id');
        $this->assertNull(LeaveRequest::query()->findOrFail($id)->leave_entitlement_id);
        $this->getJson('/api/v1/employee/leave/summary?year=2026', $this->headers($company))->assertJsonPath('data.0.available_units', 20);
    }

    public function test_started_approved_leave_and_former_employee_cannot_request_cancellation(): void
    {
        [$company, $employee, $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $id = $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'day_portion' => 'FULL_DAY', 'reason' => 'Annual rest period'], $this->headers($company, 'started'))->assertCreated()->json('id');
        $this->admin($company);
        $this->postJson("/api/v1/leave/requests/{$id}/approve", [], $this->headers($company))->assertOk();
        $this->employee($company);
        $this->travelTo(CarbonImmutable::parse('2026-10-05T00:00:00Z'));
        $this->postJson("/api/v1/employee/leave/requests/{$id}/cancel", [], $this->headers($company))->assertConflict()->assertJsonPath('error_code', 'LEAVE_CANCELLATION_TOO_LATE');
        $employee->update(['status' => 'resigned']);
        $this->getJson("/api/v1/employee/leave/requests/{$id}", $this->headers($company))->assertOk();
        $this->postJson("/api/v1/employee/leave/requests/{$id}/cancel", [], $this->headers($company))->assertForbidden();
        $this->assertSame('APPROVED', LeaveRequest::query()->findOrFail($id)->status);
    }

    public function test_entitlement_creation_replay_is_safe_and_changes_require_adjustment(): void
    {
        [$company, $employee, $type] = $this->context();
        $this->admin($company);
        $payload = ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'allocated_units' => 20];
        $this->postJson('/api/v1/leave/entitlements', $payload, $this->headers($company))->assertOk();
        $this->postJson('/api/v1/leave/entitlements', [...$payload, 'allocated_units' => 22], $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'LEAVE_ENTITLEMENT_EXISTS');
        $this->assertDatabaseCount('leave_entitlements', 1);
    }

    public function test_cancellation_rejection_requires_reason_and_keeps_approved_consumption(): void
    {
        [$company, , $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $id = $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'day_portion' => 'FULL_DAY', 'reason' => 'Annual rest period'], $this->headers($company, 'cancel-reject'))->assertCreated()->json('id');
        $this->admin($company);
        $this->postJson("/api/v1/leave/requests/{$id}/approve", [], $this->headers($company))->assertOk();
        $this->employee($company);
        $this->postJson("/api/v1/employee/leave/requests/{$id}/cancel", [], $this->headers($company))->assertJsonPath('status', 'CANCELLATION_PENDING');
        $this->admin($company);
        $this->postJson("/api/v1/leave/requests/{$id}/reject-cancellation", [], $this->headers($company))->assertUnprocessable();
        $this->postJson("/api/v1/leave/requests/{$id}/reject-cancellation", ['reason' => 'Operational coverage needed'], $this->headers($company))->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->getJson('/api/v1/leave/entitlements?employee_id='.LeaveRequest::query()->findOrFail($id)->employee_id.'&year=2026', $this->headers($company))
            ->assertJsonPath('data.0.approved_units', 2)->assertJsonPath('data.0.available_units', 18);
    }

    public function test_holiday_and_leave_records_do_not_cross_company_boundary(): void
    {
        [$company, , $type] = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $leaveId = $this->postJson('/api/v1/employee/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'day_portion' => 'FULL_DAY', 'reason' => 'Private annual leave'], $this->headers($company, 'tenant'))->assertCreated()->json('id');
        $this->admin($company);
        $holidayId = $this->postJson('/api/v1/leave/holidays', ['name' => 'Foundation Day', 'date' => '2026-10-05'], $this->headers($company))->assertCreated()->json('id');
        [, $otherCompany] = $this->actingAsCompanyUser(['leave.view', 'holiday.view', 'holiday.manage']);
        $this->getJson("/api/v1/leave/requests/{$leaveId}", $this->headers($otherCompany))->assertNotFound();
        $this->getJson('/api/v1/leave/holidays', $this->headers($otherCompany))->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/leave/holidays/{$holidayId}", ['name' => 'Changed'], $this->headers($otherCompany))->assertNotFound();
    }

    /** @return array{Company, Employee, LeaveType} */
    private function context(): array
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.leave.view', 'employee.leave.request', 'employee.leave.cancel', 'leave.view', 'leave.manage', 'leave.approve', 'holiday.view', 'holiday.manage']);
        $this->employeeUser = $user;
        $employee = Employee::factory()->for($company)->create(['created_by' => $user->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail()->forceFill(['employee_id' => $employee->id])->save();
        $type = LeaveType::query()->create(['company_id' => $company->id, 'name' => 'Annual Leave', 'is_paid' => true]);
        LeaveEntitlement::query()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'allocated_units' => 20, 'created_by' => $user->id]);

        return [$company, $employee, $type];
    }

    private function admin(Company $company): void
    {
        if ($this->adminUser !== null) {
            Sanctum::actingAs($this->adminUser);

            return;
        }
        $user = User::factory()->create();
        $this->adminUser = $user;
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Leave administrator']);
        foreach (['leave.view', 'leave.manage', 'leave.approve', 'holiday.view', 'holiday.manage'] as $permissionName) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permissionName]));
        }
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($user);
    }

    private function employee(Company $company): void
    {
        Sanctum::actingAs($this->employeeUser);
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
