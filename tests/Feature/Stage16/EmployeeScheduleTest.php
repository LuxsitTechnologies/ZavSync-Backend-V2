<?php

namespace Tests\Feature\Stage16;

use App\Http\Controllers\Api\V1\EmployeeScheduleController;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeRota;
use App\Models\EmployeeRotaSlot;
use App\Models\EmployeeShift;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftSwap;
use App\Models\EmployeeShiftSwapEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_schedule_and_consented_swap_are_private_atomic_and_accounting_safe(): void
    {
        [$company, $admin, $firstUser, $first, $secondUser, $second] = $this->context();
        $date = now($company->timezone)->addDays(7)->toDateString();
        Sanctum::actingAs($admin);
        $morning = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Morning', 'start_time' => '08:00', 'end_time' => '12:00'], $this->headers($company, 'shift-morning'))
            ->assertCreated()->json('id');
        $evening = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Evening', 'start_time' => '12:00', 'end_time' => '16:00'], $this->headers($company, 'shift-evening'))
            ->assertCreated()->json('id');
        $rota = $this->postJson('/api/v1/hrm/rotas', ['name' => 'Week', 'start_date' => $date, 'end_date' => $date], $this->headers($company, 'rota-week'))
            ->assertCreated()->json('id');
        $slotOne = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $morning, 'shift_date' => $date, 'required_coverage' => 1], $this->headers($company, 'slot-morning'))
            ->assertCreated()->json('id');
        $slotTwo = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $evening, 'shift_date' => $date, 'required_coverage' => 1], $this->headers($company, 'slot-evening'))
            ->assertCreated()->json('id');
        $from = $this->postJson("/api/v1/hrm/rota-slots/{$slotOne}/assignments", ['employee_id' => $first->id], $this->headers($company, 'assign-first'))
            ->assertCreated()->json('id');
        $to = $this->postJson("/api/v1/hrm/rota-slots/{$slotTwo}/assignments", ['employee_id' => $second->id], $this->headers($company, 'assign-second'))
            ->assertCreated()->json('id');
        Sanctum::actingAs($firstUser);
        $this->getJson("/api/v1/employee/schedule?from_date={$date}&to_date={$date}", $this->headers($company))
            ->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/hrm/rotas/{$rota}/publish", ['version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'PUBLISHED');
        Sanctum::actingAs($firstUser);
        $this->getJson("/api/v1/employee/schedule?from_date={$date}&to_date={$date}", $this->headers($company))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $from);
        $foreignAssignment = EmployeeShiftAssignment::factory()->create();
        $this->postJson('/api/v1/employee/shift-swaps', ['from_assignment_id' => $from,
            'to_assignment_id' => $foreignAssignment->id, 'reason' => 'Cross-company attempt'], $this->headers($company, 'swap-foreign-key'))
            ->assertNotFound();
        $swap = $this->postJson('/api/v1/employee/shift-swaps', ['from_assignment_id' => $from,
            'to_assignment_id' => $to, 'reason' => 'Exchange shifts'], $this->headers($company, 'swap-request-one'))
            ->assertCreated()->assertJsonPath('status', 'PENDING_TARGET')->json('id');
        $this->postJson('/api/v1/employee/shift-swaps', ['from_assignment_id' => $from,
            'to_assignment_id' => $to, 'reason' => 'Exchange shifts'], $this->headers($company, 'swap-request-one'))
            ->assertOk()->assertJsonPath('id', $swap);
        $this->postJson('/api/v1/employee/shift-swaps', ['from_assignment_id' => $from,
            'to_assignment_id' => $to, 'reason' => 'Changed reason'], $this->headers($company, 'swap-request-one'))
            ->assertConflict()->assertJsonPath('error_code', 'SCHEDULE_IDEMPOTENCY_CONFLICT');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/hrm/shift-swaps/{$swap}/approve", ['version' => 1], $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'SWAP_NOT_READY_FOR_DECISION');
        Sanctum::actingAs($secondUser);
        $this->postJson("/api/v1/employee/shift-swaps/{$swap}/accept", ['version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'PENDING_ADMIN')->assertJsonPath('version', 2);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $firstUser->id)->firstOrFail()->role
            ->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'schedules.swaps.decide']));
        Sanctum::actingAs($firstUser);
        $this->postJson("/api/v1/hrm/shift-swaps/{$swap}/approve", ['version' => 2], $this->headers($company))
            ->assertForbidden()->assertJsonPath('error_code', 'SWAP_SELF_APPROVAL_FORBIDDEN');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/hrm/shift-swaps/{$swap}/approve", ['version' => 2], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('version', 3);
        $this->assertDatabaseHas('employee_shift_assignments', ['id' => $from, 'employee_id' => $second->id, 'version' => 2]);
        $this->assertDatabaseHas('employee_shift_assignments', ['id' => $to, 'employee_id' => $first->id, 'version' => 2]);
        $this->assertDatabaseCount('employee_shift_swap_events', 3);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('attendance_sessions', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_overlapping_assignments_and_cross_company_ids_are_rejected_without_side_effects(): void
    {
        [$company, $admin, , $first] = $this->context();
        $date = now($company->timezone)->addDays(8)->toDateString();
        Sanctum::actingAs($admin);
        $shiftOne = $this->postJson('/api/v1/hrm/shifts', ['name' => 'First', 'start_time' => '08:00', 'end_time' => '12:00'], $this->headers($company, 'shift-first-two'))
            ->assertCreated()->json('id');
        $shiftTwo = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Second', 'start_time' => '11:00', 'end_time' => '15:00'], $this->headers($company, 'shift-second-two'))
            ->assertCreated()->json('id');
        $rota = $this->postJson('/api/v1/hrm/rotas', ['name' => 'Overlaps', 'start_date' => $date, 'end_date' => $date], $this->headers($company, 'rota-overlap'))
            ->assertCreated()->json('id');
        $slotOne = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $shiftOne, 'shift_date' => $date, 'required_coverage' => 1], $this->headers($company, 'slot-first-two'))
            ->assertCreated()->json('id');
        $slotTwo = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $shiftTwo, 'shift_date' => $date, 'required_coverage' => 1], $this->headers($company, 'slot-second-two'))
            ->assertCreated()->json('id');
        $this->postJson("/api/v1/hrm/rota-slots/{$slotOne}/assignments", ['employee_id' => $first->id], $this->headers($company, 'assign-first-two'))
            ->assertCreated();
        $this->postJson("/api/v1/hrm/rota-slots/{$slotTwo}/assignments", ['employee_id' => $first->id], $this->headers($company, 'assign-overlap'))
            ->assertConflict()->assertJsonPath('error_code', 'SCHEDULE_ASSIGNMENT_CONFLICT');
        $foreign = Company::factory()->create();
        $foreignEmployee = Employee::factory()->for($foreign)->create();
        $this->postJson("/api/v1/hrm/rota-slots/{$slotTwo}/assignments", ['employee_id' => $foreignEmployee->id], $this->headers($company, 'assign-foreign'))
            ->assertNotFound();
        $this->assertDatabaseCount('employee_shift_assignments', 1);
    }

    public function test_shift_creation_is_exactly_idempotent_and_overnight_or_unauthorized_creation_is_rejected(): void
    {
        [$company, $admin, $employeeUser] = $this->context();
        Sanctum::actingAs($admin);
        $payload = ['name' => 'Standard', 'start_time' => '09:00', 'end_time' => '17:00'];
        $created = $this->postJson('/api/v1/hrm/shifts', $payload, $this->headers($company, 'shift-exact-key'))
            ->assertCreated()->assertJsonPath('version', 1);
        $this->postJson('/api/v1/hrm/shifts', $payload, $this->headers($company, 'shift-exact-key'))
            ->assertOk()->assertJsonPath('id', $created->json('id'));
        $this->postJson('/api/v1/hrm/shifts', [...$payload, 'name' => 'Changed'], $this->headers($company, 'shift-exact-key'))
            ->assertConflict()->assertJsonPath('error_code', 'SCHEDULE_IDEMPOTENCY_CONFLICT');
        $this->postJson('/api/v1/hrm/shifts', ['name' => 'Overnight', 'start_time' => '22:00', 'end_time' => '06:00'],
            $this->headers($company, 'shift-overnight-key'))->assertUnprocessable();
        Sanctum::actingAs($employeeUser);
        $this->postJson('/api/v1/hrm/shifts', $payload, $this->headers($company, 'shift-employee-key'))->assertForbidden();
        $this->assertDatabaseCount('employee_shifts', 1);
    }

    public function test_schedule_factories_preserve_company_foreign_keys(): void
    {
        $slot = EmployeeRotaSlot::factory()->create();
        $assignment = EmployeeShiftAssignment::factory()->create();
        $swap = EmployeeShiftSwap::factory()->create();
        $event = EmployeeShiftSwapEvent::factory()->create();

        $this->assertSame($slot->company_id, $slot->fresh()->company_id);
        $this->assertSame($assignment->company_id, $assignment->fresh()->company_id);
        $this->assertSame($swap->company_id, $swap->fresh()->company_id);
        $this->assertSame($event->company_id, $event->fresh()->company_id);
    }

    public function test_direct_assignment_controller_path_used_by_concurrency_harness(): void
    {
        [$company, $admin, , $employee] = $this->context();
        $date = now($company->timezone)->addDays(7)->toDateString();
        $rota = EmployeeRota::factory()->create(['company_id' => $company->id, 'start_date' => $date, 'end_date' => $date]);
        $shift = EmployeeShift::factory()->create(['company_id' => $company->id]);
        $slot = EmployeeRotaSlot::factory()->create(['company_id' => $company->id, 'employee_rota_id' => $rota->id,
            'employee_shift_id' => $shift->id, 'shift_date' => $date]);
        $request = Request::create('/api/v1/hrm/rota-slots/assignments', 'POST', ['employee_id' => $employee->id]);
        $request->headers->set('Idempotency-Key', 'direct-assignment-key');
        $request->attributes->set('company_id', $company->id);
        $request->setUserResolver(fn (): User => $admin);

        $this->assertSame(201, app(EmployeeScheduleController::class)->assign($request, $slot->id)->getStatusCode());
        $this->assertDatabaseCount('employee_shift_assignments', 1);
    }

    public function test_former_employee_can_read_only_historical_published_schedule(): void
    {
        [$company, , $employeeUser, $employee] = $this->context();
        $past = now($company->timezone)->subDays(7)->toDateString();
        $future = now($company->timezone)->addDays(7)->toDateString();
        $shift = EmployeeShift::factory()->create(['company_id' => $company->id]);
        foreach ([$past, $future] as $date) {
            $rota = EmployeeRota::factory()->create(['company_id' => $company->id, 'start_date' => $date, 'end_date' => $date]);
            $rota->forceFill(['status' => 'PUBLISHED', 'published_at' => now()])->save();
            $slot = EmployeeRotaSlot::factory()->create(['company_id' => $company->id, 'employee_rota_id' => $rota->id,
                'employee_shift_id' => $shift->id, 'shift_date' => $date]);
            EmployeeShiftAssignment::factory()->create(['company_id' => $company->id,
                'employee_rota_slot_id' => $slot->id, 'employee_id' => $employee->id]);
        }
        $employee->forceFill(['status' => 'resigned'])->save();
        Sanctum::actingAs($employeeUser);
        $this->getJson("/api/v1/employee/schedule?from_date={$past}&to_date={$future}", $this->headers($company))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shift_date', $past);
        $tooFar = now($company->timezone)->addYears(2)->toDateString();
        $this->getJson("/api/v1/employee/schedule?from_date={$past}&to_date={$tooFar}", $this->headers($company))
            ->assertUnprocessable()->assertJsonPath('error_code', 'SCHEDULE_RANGE_TOO_LARGE');
    }

    /** @return array{Company, User, User, Employee, User, Employee} */
    private function context(): array
    {
        [$firstUser, $company] = $this->actingAsCompanyUser(['employee.schedule.view', 'employee.schedule.swap.request']);
        $first = Employee::factory()->for($company)->create();
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $firstUser->id)->firstOrFail()
            ->forceFill(['employee_id' => $first->id])->save();
        $secondUser = User::factory()->create();
        $second = Employee::factory()->for($company)->create();
        $this->member($company, $secondUser, $second, ['employee.schedule.view', 'employee.schedule.swap.respond']);
        $admin = User::factory()->create();
        $this->member($company, $admin, null, ['schedules.view', 'schedules.manage', 'schedules.swaps.decide']);

        return [$company, $admin, $firstUser, $first, $secondUser, $second];
    }

    /** @param array<int, string> $permissions */
    private function member(Company $company, User $user, ?Employee $employee, array $permissions): void
    {
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Schedule role '.fake()->unique()->numerify('#####')]);
        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission]));
        }
        $membership = CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id, 'is_active' => true]);
        $membership->forceFill(['employee_id' => $employee?->id])->save();
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
