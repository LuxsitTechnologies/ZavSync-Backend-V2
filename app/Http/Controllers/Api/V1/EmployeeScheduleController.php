<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeRota;
use App\Models\EmployeeRotaSlot;
use App\Models\EmployeeShift;
use App\Models\EmployeeShiftAssignment;
use App\Services\AuditService;
use App\Services\Platform\NotificationService;
use App\Services\Scheduling\EmployeeScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeScheduleController extends Controller
{
    public function __construct(
        private readonly EmployeeScheduleService $schedule,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function shifts(Request $request): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.view');
        $page = EmployeeShift::query()->where('company_id', $companyId)->orderBy('name')->paginate(50);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeShift $shift): array => $this->shiftCard($shift))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function storeShift(Request $request): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time']]);
        $keyHash = $this->schedule->keyHash($request);
        $payloadHash = hash('sha256', json_encode([$data['name'], $data['start_time'], $data['end_time']], JSON_THROW_ON_ERROR));
        $shift = DB::transaction(function () use ($request, $companyId, $data, $keyHash, $payloadHash): EmployeeShift {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = EmployeeShift::query()->where('company_id', $companyId)->where('created_by', $request->user()->id)
                ->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                $this->schedule->assertFingerprint($prior->create_payload_hash, $payloadHash);

                return $prior;
            }
            if (EmployeeShift::query()->where('company_id', $companyId)->where('name', $data['name'])->exists()) {
                throw new PlatformException('SHIFT_NAME_CONFLICT', 'A shift with this name already exists.', 409);
            }
            $created = EmployeeShift::query()->create(['company_id' => $companyId, ...$data, 'created_by' => $request->user()->id]);
            $created->forceFill(['create_request_key_hash' => $keyHash, 'create_payload_hash' => $payloadHash])->save();
            $created->refresh();
            $this->audit->record($request, $request->user(), $companyId, 'employee_shift_created', 'employee_shifts', $created);

            return $created;
        });

        return response()->json($this->shiftCard($shift), $shift->wasRecentlyCreated ? 201 : 200);
    }

    public function rotas(Request $request): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = EmployeeRota::query()->where('company_id', $companyId)->orderByDesc('start_date')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeRota $rota): array => $this->rotaCard($rota))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function storeRota(Request $request): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date']]);
        $keyHash = $this->schedule->keyHash($request);
        $payloadHash = hash('sha256', json_encode([$data['name'], $data['start_date'], $data['end_date']], JSON_THROW_ON_ERROR));
        $rota = DB::transaction(function () use ($request, $companyId, $data, $keyHash, $payloadHash): EmployeeRota {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = EmployeeRota::query()->where('company_id', $companyId)->where('created_by', $request->user()->id)
                ->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                $this->schedule->assertFingerprint($prior->create_payload_hash, $payloadHash);

                return $prior;
            }
            $this->schedule->assertFutureDate($companyId, $data['start_date']);
            $created = EmployeeRota::query()->create(['company_id' => $companyId, ...$data,
                'timezone' => $company->timezone, 'created_by' => $request->user()->id]);
            $created->forceFill(['create_request_key_hash' => $keyHash, 'create_payload_hash' => $payloadHash])->save();
            $created->refresh();
            $this->audit->record($request, $request->user(), $companyId, 'employee_rota_created', 'employee_rotas', $created);

            return $created;
        });

        return response()->json($this->rotaCard($rota), $rota->wasRecentlyCreated ? 201 : 200);
    }

    public function showRota(Request $request, string $rota): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.view');
        $model = EmployeeRota::query()->where('company_id', $companyId)->findOrFail($rota);
        $slots = EmployeeRotaSlot::query()->where('company_id', $companyId)->where('employee_rota_id', $rota)
            ->orderBy('shift_date')->orderBy('start_time')->get();
        $assignments = EmployeeShiftAssignment::query()->where('company_id', $companyId)
            ->whereIn('employee_rota_slot_id', $slots->pluck('id'))->get()->groupBy('employee_rota_slot_id');

        return response()->json(['rota' => $this->rotaCard($model), 'slots' => $slots->map(function (EmployeeRotaSlot $slot) use ($assignments): array {
            $assigned = $assignments->get($slot->id, collect());

            return [...$this->slotCard($slot), 'assignments' => $assigned->map(fn (EmployeeShiftAssignment $assignment): array => $this->assignmentCard($assignment))->all(),
                'assigned_count' => $assigned->count(), 'coverage_gap' => max(0, $slot->required_coverage - $assigned->count())];
        })->all()]);
    }

    public function storeSlot(Request $request, string $rota): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.manage');
        $data = $request->validate(['shift_id' => ['required', 'uuid'], 'shift_date' => ['required', 'date_format:Y-m-d'],
            'required_coverage' => ['required', 'integer', 'between:1,1000']]);
        $keyHash = $this->schedule->keyHash($request);
        $payloadHash = hash('sha256', json_encode([$rota, $data['shift_id'], $data['shift_date'], $data['required_coverage']], JSON_THROW_ON_ERROR));
        $slot = DB::transaction(function () use ($request, $companyId, $rota, $data, $keyHash, $payloadHash): EmployeeRotaSlot {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = EmployeeRotaSlot::query()->where('company_id', $companyId)->where('created_by', $request->user()->id)
                ->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                $this->schedule->assertFingerprint($prior->create_payload_hash, $payloadHash);

                return $prior;
            }
            $current = EmployeeRota::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($rota);
            if ($current->status !== 'DRAFT') {
                throw new PlatformException('ROTA_PUBLISHED', 'Published rota slots cannot be edited.', 409);
            }
            $this->schedule->assertFutureDate($companyId, $data['shift_date']);
            if ($data['shift_date'] < $current->start_date->toDateString() || $data['shift_date'] > $current->end_date->toDateString()) {
                throw new PlatformException('ROTA_DATE_OUTSIDE_PERIOD', 'The slot date is outside the rota period.', 422);
            }
            $shift = EmployeeShift::query()->where('company_id', $companyId)->findOrFail($data['shift_id']);
            if (! $shift->is_active) {
                throw new PlatformException('SHIFT_INACTIVE', 'An inactive shift cannot be scheduled.', 409);
            }
            if (EmployeeRotaSlot::query()->where('employee_rota_id', $rota)->where('employee_shift_id', $shift->id)
                ->whereDate('shift_date', $data['shift_date'])->exists()) {
                throw new PlatformException('ROTA_SLOT_DUPLICATE', 'This shift is already scheduled on this date.', 409);
            }
            $created = EmployeeRotaSlot::query()->create(['company_id' => $companyId, 'employee_rota_id' => $rota,
                'employee_shift_id' => $shift->id, 'shift_date' => $data['shift_date'], 'start_time' => $shift->start_time,
                'end_time' => $shift->end_time, 'required_coverage' => $data['required_coverage'], 'created_by' => $request->user()->id]);
            $created->forceFill(['create_request_key_hash' => $keyHash, 'create_payload_hash' => $payloadHash])->save();
            $created->refresh();
            $this->audit->record($request, $request->user(), $companyId, 'employee_rota_slot_created', 'employee_rota_slots', $created);

            return $created;
        });

        return response()->json($this->slotCard($slot), $slot->wasRecentlyCreated ? 201 : 200);
    }

    public function assign(Request $request, string $slot): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.manage');
        $data = $request->validate(['employee_id' => ['required', 'uuid']]);
        $keyHash = $this->schedule->keyHash($request);
        $payloadHash = hash('sha256', json_encode([$slot, $data['employee_id']], JSON_THROW_ON_ERROR));
        $assignment = DB::transaction(function () use ($request, $companyId, $slot, $data, $keyHash, $payloadHash): EmployeeShiftAssignment {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = EmployeeShiftAssignment::query()->where('company_id', $companyId)->where('created_by', $request->user()->id)
                ->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                $this->schedule->assertFingerprint($prior->create_payload_hash, $payloadHash);

                return $prior;
            }
            $current = EmployeeRotaSlot::query()->where('company_id', $companyId)->findOrFail($slot);
            $this->schedule->assertFutureDate($companyId, $current->shift_date->toDateString());
            $employee = Employee::query()->where('company_id', $companyId)->findOrFail($data['employee_id']);
            $this->schedule->assertCurrent($employee);
            $this->schedule->assertNoConflict($companyId, $employee->id, $current->shift_date->toDateString(), $current->start_time, $current->end_time);
            $created = EmployeeShiftAssignment::query()->create(['company_id' => $companyId, 'employee_rota_slot_id' => $slot,
                'employee_id' => $employee->id, 'created_by' => $request->user()->id]);
            $created->forceFill(['create_request_key_hash' => $keyHash, 'create_payload_hash' => $payloadHash])->save();
            $created->refresh();
            $this->audit->record($request, $request->user(), $companyId, 'employee_shift_assigned', 'employee_shift_assignments', $created);
            $rota = EmployeeRota::query()->where('company_id', $companyId)->findOrFail($current->employee_rota_id);
            if ($rota->status === 'PUBLISHED') {
                $recipientId = CompanyUser::query()->where('company_id', $companyId)->where('employee_id', $employee->id)
                    ->where('is_active', true)->value('user_id');
                if ($recipientId !== null && (int) $recipientId !== $request->user()->id) {
                    $this->notifications->createInApp($companyId, (int) $recipientId, 'schedule.assigned',
                        'New shift assigned', 'A shift was added to your published schedule.', ['assignment_id' => $created->id]);
                }
            }

            return $created;
        });

        return response()->json($this->assignmentCard($assignment), $assignment->wasRecentlyCreated ? 201 : 200);
    }

    public function publish(Request $request, string $rota): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $rota, $data): EmployeeRota {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = EmployeeRota::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($rota);
            if ($current->status === 'PUBLISHED') {
                return $current;
            }
            $this->schedule->assertVersion($current->version, (int) $data['version']);
            if (! EmployeeRotaSlot::query()->where('company_id', $companyId)->where('employee_rota_id', $rota)->exists()) {
                throw new PlatformException('ROTA_EMPTY', 'Add at least one slot before publishing.', 409);
            }
            $current->forceFill(['status' => 'PUBLISHED', 'version' => $current->version + 1,
                'published_at' => now(), 'published_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_rota_published', 'employee_rotas', $current);
            $employeeIds = EmployeeShiftAssignment::query()->join('employee_rota_slots as slots',
                'slots.id', '=', 'employee_shift_assignments.employee_rota_slot_id')
                ->where('employee_shift_assignments.company_id', $companyId)->where('slots.employee_rota_id', $rota)
                ->distinct()->pluck('employee_shift_assignments.employee_id');
            CompanyUser::query()->where('company_id', $companyId)->whereIn('employee_id', $employeeIds)
                ->where('is_active', true)->where('user_id', '!=', $request->user()->id)
                ->pluck('user_id')->unique()->each(function (int $userId) use ($companyId, $current): void {
                    $this->notifications->createInApp($companyId, $userId, 'schedule.published',
                        'Schedule published', 'A schedule containing your shift was published.', ['rota_id' => $current->id]);
                });

            return $current;
        });

        return response()->json($this->rotaCard($model));
    }

    public function mySchedule(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->schedule->employeeAccess($request, 'employee.schedule.view');
        $data = $request->validate(['from_date' => ['required', 'date_format:Y-m-d'], 'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date']]);
        if ($data['to_date'] > CarbonImmutable::parse($data['from_date'])->addYear()->toDateString()) {
            throw new PlatformException('SCHEDULE_RANGE_TOO_LARGE', 'Schedule reads are limited to one year.', 422);
        }
        $effectiveToDate = $data['to_date'];
        if (in_array($employee->status, ['resigned', 'terminated'], true)) {
            $timezone = (string) Company::query()->whereKey($companyId)->value('timezone');
            $effectiveToDate = min($effectiveToDate, now($timezone)->subDay()->toDateString());
        }
        $assignments = EmployeeShiftAssignment::query()->join('employee_rota_slots as slots',
            'slots.id', '=', 'employee_shift_assignments.employee_rota_slot_id')
            ->join('employee_rotas as rotas', 'rotas.id', '=', 'slots.employee_rota_id')
            ->join('employee_shifts as shifts', 'shifts.id', '=', 'slots.employee_shift_id')
            ->where('employee_shift_assignments.company_id', $companyId)->where('employee_shift_assignments.employee_id', $employee->id)
            ->where('rotas.status', 'PUBLISHED')->whereDate('slots.shift_date', '>=', $data['from_date'])
            ->whereDate('slots.shift_date', '<=', $effectiveToDate)
            ->orderBy('slots.shift_date')->orderBy('slots.start_time')
            ->get(['employee_shift_assignments.id', 'employee_shift_assignments.employee_rota_slot_id',
                'employee_shift_assignments.version', 'slots.shift_date', 'slots.start_time', 'slots.end_time',
                'rotas.timezone', 'shifts.name as shift_name']);

        return response()->json(['data' => $assignments->map(fn ($assignment): array => ['id' => $assignment->id,
            'slot_id' => $assignment->employee_rota_slot_id, 'version' => $assignment->version,
            'shift_date' => substr((string) $assignment->shift_date, 0, 10), 'start_time' => $assignment->start_time,
            'end_time' => $assignment->end_time, 'timezone' => $assignment->timezone,
            'shift_name' => $assignment->shift_name])->all()]);
    }

    private function shiftCard(EmployeeShift $shift): array
    {
        return ['id' => $shift->id, 'name' => $shift->name, 'start_time' => $shift->start_time,
            'end_time' => $shift->end_time, 'is_active' => $shift->is_active, 'version' => $shift->version];
    }

    private function rotaCard(EmployeeRota $rota): array
    {
        return ['id' => $rota->id, 'name' => $rota->name, 'start_date' => $rota->start_date->toDateString(),
            'end_date' => $rota->end_date->toDateString(), 'timezone' => $rota->timezone, 'status' => $rota->status,
            'version' => $rota->version, 'published_at' => $rota->published_at?->toIso8601String()];
    }

    private function slotCard(EmployeeRotaSlot $slot): array
    {
        return ['id' => $slot->id, 'rota_id' => $slot->employee_rota_id, 'shift_id' => $slot->employee_shift_id,
            'shift_date' => $slot->shift_date->toDateString(), 'start_time' => $slot->start_time,
            'end_time' => $slot->end_time, 'required_coverage' => $slot->required_coverage, 'version' => $slot->version];
    }

    private function assignmentCard(EmployeeShiftAssignment $assignment): array
    {
        return ['id' => $assignment->id, 'slot_id' => $assignment->employee_rota_slot_id,
            'employee_id' => $assignment->employee_id, 'version' => $assignment->version];
    }
}
