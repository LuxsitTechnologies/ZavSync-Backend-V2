<?php

namespace Tests\Feature\Stage16;

use App\Exceptions\PlatformException;
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
use App\Services\Scheduling\ShiftInterval;
use Carbon\CarbonImmutable;
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

    public function test_shift_creation_is_exactly_idempotent_and_overnight_is_authorized(): void
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
            $this->headers($company, 'shift-overnight-key'))->assertCreated()->assertJsonPath('ends_next_day', true);
        $this->postJson('/api/v1/hrm/shifts', ['name' => 'Zero', 'start_time' => '22:00', 'end_time' => '22:00'],
            $this->headers($company, 'shift-zero-key'))->assertUnprocessable();
        Sanctum::actingAs($employeeUser);
        $this->postJson('/api/v1/hrm/shifts', $payload, $this->headers($company, 'shift-employee-key'))->assertForbidden();
        $this->assertDatabaseCount('employee_shifts', 2);
    }

    public function test_unused_shift_can_be_updated_with_version_and_company_authorization(): void
    {
        [$company, $admin, $employeeUser] = $this->context();
        Sanctum::actingAs($admin);
        $shift = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Day template', 'start_time' => '09:00', 'end_time' => '17:00'],
            $this->headers($company, 'editable-shift'))->assertCreated()->json('id');
        $path = "/api/v1/hrm/shifts/{$shift}";
        $change = ['version' => 1, 'name' => 'Night template', 'start_time' => '22:00', 'end_time' => '06:00'];
        $this->putJson($path, $change, $this->headers($company))->assertOk()
            ->assertJsonPath('version', 2)->assertJsonPath('ends_next_day', true)
            ->assertJsonPath('is_active', true);
        $this->putJson($path, $change, $this->headers($company))->assertConflict()
            ->assertJsonPath('error_code', 'SCHEDULE_VERSION_STALE');
        $this->putJson($path, [...$change, 'version' => 2, 'end_time' => '22:00'], $this->headers($company))
            ->assertUnprocessable();
        $this->assertDatabaseHas('employee_shifts', ['id' => $shift, 'start_time' => '22:00', 'end_time' => '06:00', 'version' => 2]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'employee_shift_updated']);
        Sanctum::actingAs($employeeUser);
        $this->putJson($path, [...$change, 'version' => 2], $this->headers($company))->assertForbidden();
        Sanctum::actingAs($admin);
        $foreign = EmployeeShift::factory()->create();
        $this->putJson("/api/v1/hrm/shifts/{$foreign->id}", [...$change, 'version' => 1], $this->headers($company))
            ->assertNotFound();
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('attendance_sessions', 0);
    }

    public function test_shift_used_by_draft_or_published_rota_cannot_rewrite_assignment_or_attendance_contract(): void
    {
        [$company, $admin, , $employee] = $this->context();
        $date = now($company->timezone)->addDays(7)->toDateString();
        Sanctum::actingAs($admin);
        $shift = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Assigned night', 'start_time' => '22:00', 'end_time' => '06:00'],
            $this->headers($company, 'assigned-night'))->assertCreated()->json('id');
        $rota = $this->postJson('/api/v1/hrm/rotas', ['name' => 'Protected rota', 'start_date' => $date, 'end_date' => $date],
            $this->headers($company, 'protected-rota'))->assertCreated()->json('id');
        $slot = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $shift, 'shift_date' => $date,
            'required_coverage' => 1], $this->headers($company, 'protected-slot'))->assertCreated()->json('id');
        $change = ['version' => 1, 'name' => 'Rewritten night', 'start_time' => '21:00', 'end_time' => '05:00'];
        $this->putJson("/api/v1/hrm/shifts/{$shift}", $change, $this->headers($company))->assertConflict()
            ->assertJsonPath('error_code', 'SHIFT_IN_USE');
        $this->postJson("/api/v1/hrm/rota-slots/{$slot}/assignments", ['employee_id' => $employee->id],
            $this->headers($company, 'protected-assignment'))->assertCreated();
        $this->postJson("/api/v1/hrm/rotas/{$rota}/publish", ['version' => 1], $this->headers($company))->assertOk();
        $this->putJson("/api/v1/hrm/shifts/{$shift}", $change, $this->headers($company))->assertConflict()
            ->assertJsonPath('error_code', 'SHIFT_IN_USE');
        $this->assertDatabaseHas('employee_shifts', ['id' => $shift, 'name' => 'Assigned night', 'version' => 1]);
        $this->assertDatabaseHas('employee_rota_slots', ['id' => $slot, 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->assertDatabaseCount('attendance_sessions', 0);
        $this->assertDatabaseCount('payroll_entries', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_edited_overnight_template_uses_dated_dst_and_cross_midnight_assignment_checks(): void
    {
        [$company, $admin, , $employee] = $this->context();
        $company->forceFill(['timezone' => 'America/New_York'])->save();
        Sanctum::actingAs($admin);
        $night = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Editable', 'start_time' => '08:00', 'end_time' => '16:00'],
            $this->headers($company, 'edited-dst-night'))->assertCreated()->json('id');
        $this->putJson("/api/v1/hrm/shifts/{$night}", ['version' => 1, 'name' => 'Edited night',
            'start_time' => '22:00', 'end_time' => '06:00'], $this->headers($company))
            ->assertOk()->assertJsonPath('ends_next_day', true);
        $overlap = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Early', 'start_time' => '05:00', 'end_time' => '08:00'],
            $this->headers($company, 'edited-dst-early'))->assertCreated()->json('id');
        $date = now($company->timezone)->addDays(7)->toDateString();
        $next = CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString();
        $rota = $this->postJson('/api/v1/hrm/rotas', ['name' => 'Edited template rota', 'start_date' => $date,
            'end_date' => $next], $this->headers($company, 'edited-dst-rota'))->assertCreated()->json('id');
        $nightSlot = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $night,
            'shift_date' => $date, 'required_coverage' => 1], $this->headers($company, 'edited-dst-night-slot'))
            ->assertCreated()->json('id');
        $overlapSlot = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $overlap,
            'shift_date' => $next, 'required_coverage' => 1], $this->headers($company, 'edited-dst-early-slot'))
            ->assertCreated()->json('id');
        $this->postJson("/api/v1/hrm/rota-slots/{$nightSlot}/assignments", ['employee_id' => $employee->id],
            $this->headers($company, 'edited-dst-night-assignment'))->assertCreated();
        $this->postJson("/api/v1/hrm/rota-slots/{$overlapSlot}/assignments", ['employee_id' => $employee->id],
            $this->headers($company, 'edited-dst-early-assignment'))->assertConflict()
            ->assertJsonPath('error_code', 'SCHEDULE_ASSIGNMENT_CONFLICT');

        $dstShift = $this->postJson('/api/v1/hrm/shifts', ['name' => 'DST template', 'start_time' => '08:00', 'end_time' => '16:00'],
            $this->headers($company, 'edited-dst-template'))->assertCreated()->json('id');
        $this->putJson("/api/v1/hrm/shifts/{$dstShift}", ['version' => 1, 'name' => 'DST template',
            'start_time' => '01:30', 'end_time' => '03:30'], $this->headers($company))->assertOk();
        $dstRota = $this->postJson('/api/v1/hrm/rotas', ['name' => 'DST invalid rota', 'start_date' => '2027-11-07',
            'end_date' => '2027-11-07'], $this->headers($company, 'edited-dst-invalid-rota'))->assertCreated()->json('id');
        $this->postJson("/api/v1/hrm/rotas/{$dstRota}/slots", ['shift_id' => $dstShift,
            'shift_date' => '2027-11-07', 'required_coverage' => 1], $this->headers($company, 'edited-dst-invalid-slot'))
            ->assertUnprocessable()->assertJsonPath('error_code', 'SCHEDULE_LOCAL_TIME_INVALID');
    }

    public function test_overnight_assignment_blocks_next_date_overlap_and_accepts_adjacent_shift(): void
    {
        [$company, $admin, , $employee] = $this->context();
        $date = now($company->timezone)->addDays(7)->toDateString();
        $next = CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString();
        Sanctum::actingAs($admin);
        $night = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00'],
            $this->headers($company, 'night-shift-key'))->assertCreated()->json('id');
        $overlap = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Overlap', 'start_time' => '05:00', 'end_time' => '07:00'],
            $this->headers($company, 'overlap-shift-key'))->assertCreated()->json('id');
        $adjacent = $this->postJson('/api/v1/hrm/shifts', ['name' => 'Adjacent', 'start_time' => '06:00', 'end_time' => '08:00'],
            $this->headers($company, 'adjacent-shift-key'))->assertCreated()->json('id');
        $rota = $this->postJson('/api/v1/hrm/rotas', ['name' => 'Night rota', 'start_date' => $date, 'end_date' => $next],
            $this->headers($company, 'night-rota-key'))->assertCreated()->json('id');
        $nightSlot = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $night, 'shift_date' => $date,
            'required_coverage' => 1], $this->headers($company, 'night-slot-key'))->assertCreated()
            ->assertJsonPath('ends_next_day', true)->json('id');
        $overlapSlot = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $overlap, 'shift_date' => $next,
            'required_coverage' => 1], $this->headers($company, 'overlap-slot-key'))->assertCreated()->json('id');
        $adjacentSlot = $this->postJson("/api/v1/hrm/rotas/{$rota}/slots", ['shift_id' => $adjacent, 'shift_date' => $next,
            'required_coverage' => 1], $this->headers($company, 'adjacent-slot-key'))->assertCreated()->json('id');
        $this->postJson("/api/v1/hrm/rota-slots/{$nightSlot}/assignments", ['employee_id' => $employee->id],
            $this->headers($company, 'night-assignment-key'))->assertCreated();
        $this->postJson("/api/v1/hrm/rota-slots/{$overlapSlot}/assignments", ['employee_id' => $employee->id],
            $this->headers($company, 'overlap-assignment-key'))->assertConflict()->assertJsonPath('error_code', 'SCHEDULE_ASSIGNMENT_CONFLICT');
        $this->postJson("/api/v1/hrm/rota-slots/{$adjacentSlot}/assignments", ['employee_id' => $employee->id],
            $this->headers($company, 'adjacent-assignment-key'))->assertCreated();
        $this->assertDatabaseCount('employee_shift_assignments', 2);
    }

    public function test_dst_missing_and_ambiguous_local_shift_boundaries_are_rejected(): void
    {
        $interval = app(ShiftInterval::class);
        foreach (['2027-03-14' => ['01:30', '02:30'], '2027-11-07' => ['01:30', '03:00']] as $date => [$start, $end]) {
            try {
                $interval->resolve($date, $start, $end, 'America/New_York');
                $this->fail('A nonexistent or ambiguous local boundary must be rejected.');
            } catch (PlatformException $exception) {
                $this->assertSame('SCHEDULE_LOCAL_TIME_INVALID', $exception->errorCode);
            }
        }
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
