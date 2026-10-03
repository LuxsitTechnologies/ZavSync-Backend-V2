<?php

namespace App\Services\Leave;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveEntitlementAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestEvent;
use App\Models\LeaveType;
use App\Services\AuditService;
use App\Services\Platform\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    public function __construct(private readonly AuditService $audit, private readonly NotificationService $notifications) {}

    /** @return array<string, int|null> */
    public function balance(LeaveEntitlement $entitlement): array
    {
        $adjusted = (int) LeaveEntitlementAdjustment::query()->where('company_id', $entitlement->company_id)
            ->where('leave_entitlement_id', $entitlement->id)->sum('delta_units');
        $base = $entitlement->allocated_units + $adjusted;
        $pending = (int) LeaveRequest::query()->where('company_id', $entitlement->company_id)
            ->where('leave_entitlement_id', $entitlement->id)->where('status', 'PENDING')->sum('units');
        $approved = (int) LeaveRequest::query()->where('company_id', $entitlement->company_id)
            ->where('leave_entitlement_id', $entitlement->id)->whereIn('status', ['APPROVED', 'CANCELLATION_PENDING'])->sum('units');

        return ['allocated_units' => $entitlement->allocated_units, 'adjustment_units' => $adjusted,
            'pending_units' => $pending, 'approved_units' => $approved, 'available_units' => $base - $pending - $approved];
    }

    /** @return array<string, mixed> */
    public function present(LeaveRequest $leave, bool $includeEvents = false): array
    {
        $data = [
            'id' => $leave->id, 'employee_id' => $leave->employee_id,
            'leave_type_id' => $leave->leave_type_id, 'type_name' => $leave->type_name_snapshot,
            'is_paid' => $leave->is_paid_snapshot, 'start_date' => $leave->start_date->toDateString(),
            'end_date' => $leave->end_date->toDateString(), 'day_portion' => $leave->day_portion,
            'units' => $leave->units, 'status' => $leave->status, 'reason' => $leave->reason,
            'submitted_at' => $leave->created_at?->toIso8601String(),
        ];
        if ($includeEvents) {
            $data['events'] = $leave->events()->orderBy('created_at')->orderBy('id')->get()->map(fn (LeaveRequestEvent $event): array => [
                'id' => $event->id, 'action' => $event->action, 'from_status' => $event->from_status,
                'to_status' => $event->to_status, 'reason' => $event->reason,
                'actor_id' => $event->actor_id, 'created_at' => $event->created_at?->toIso8601String(),
            ])->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function presentForAdmin(LeaveRequest $leave, ?string $viewerEmployeeId, bool $includeEvents = false): array
    {
        return [
            ...$this->present($leave, $includeEvents),
            'is_own_request' => $viewerEmployeeId !== null && $viewerEmployeeId === $leave->employee_id,
        ];
    }

    /** @param array<string, mixed> $data */
    public function submit(Request $request, string $companyId, string $employeeId, array $data, string $key): LeaveRequest
    {
        $this->assertKey($key);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['start_date'], 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $data['end_date'], 'UTC');
        if ($start === false || $end === false || $end->lt($start) || $start->diff($end)->days > 365) {
            throw new PlatformException('LEAVE_DATES_INVALID', 'Leave dates must form a range of at most 366 calendar days.', 422);
        }
        $portion = $data['day_portion'];
        if ($portion !== 'FULL_DAY' && ! $start->isSameDay($end)) {
            throw new PlatformException('LEAVE_HALF_DAY_RANGE_INVALID', 'Half-day leave must cover exactly one date.', 422);
        }
        $units = $portion === 'FULL_DAY' ? ($start->diff($end)->days + 1) * 2 : 1;
        $payloadHash = hash('sha256', json_encode([$data['leave_type_id'], $data['start_date'], $data['end_date'], $portion, $data['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $companyId, $employeeId, $data, $key, $start, $end, $portion, $units, $payloadHash): LeaveRequest {
            $employee = Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($employeeId);
            $prior = LeaveRequest::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
                ->where('request_key_hash', hash('sha256', $key))->first();
            if ($prior !== null) {
                if ($prior->payload_hash !== $payloadHash) {
                    throw new PlatformException('IDEMPOTENCY_PAYLOAD_CONFLICT', 'This key belongs to a different leave request.', 409);
                }

                return $prior;
            }
            if (in_array(mb_strtolower($employee->status), ['resigned', 'terminated'], true)) {
                throw new PlatformException('EMPLOYEE_LEAVE_INACTIVE', 'Former employees cannot request new leave.', 403);
            }
            $timezone = (string) Company::query()->whereKey($companyId)->value('timezone');
            if ($start->toDateString() < CarbonImmutable::now($timezone)->toDateString()) {
                throw new PlatformException('LEAVE_PAST_DATE', 'New leave requests cannot start in the past.', 422);
            }
            $type = LeaveType::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($data['leave_type_id']);
            $overlapping = LeaveRequest::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
                ->whereIn('status', ['PENDING', 'APPROVED', 'CANCELLATION_PENDING'])
                ->where('start_date', '<=', $end->toDateString())->where('end_date', '>=', $start->toDateString())->get();
            foreach ($overlapping as $existing) {
                if ($portion !== 'FULL_DAY' && $existing->day_portion !== 'FULL_DAY'
                    && $existing->start_date->toDateString() === $start->toDateString()
                    && $existing->day_portion !== $portion) {
                    continue;
                }
                throw new PlatformException('LEAVE_OVERLAP', 'This leave overlaps an existing pending or approved request.', 409);
            }
            $entitlement = null;
            if ($type->is_paid) {
                if ($start->year !== $end->year) {
                    throw new PlatformException('LEAVE_ENTITLEMENT_PERIOD', 'Paid leave cannot cross entitlement years.', 422);
                }
                $entitlement = LeaveEntitlement::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
                    ->where('leave_type_id', $type->id)->where('year', $start->year)->first();
                if ($entitlement === null || $this->balance($entitlement)['available_units'] < $units) {
                    throw new PlatformException('LEAVE_BALANCE_INSUFFICIENT', 'Available leave entitlement is insufficient.', 409);
                }
            }
            $leave = LeaveRequest::query()->create([
                'company_id' => $companyId, 'employee_id' => $employeeId, 'leave_type_id' => $type->id,
                'leave_entitlement_id' => $entitlement?->id, 'type_name_snapshot' => $type->name,
                'is_paid_snapshot' => $type->is_paid, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
                'day_portion' => $portion, 'units' => $units, 'status' => 'PENDING', 'reason' => $data['reason'],
                'request_key_hash' => hash('sha256', $key), 'payload_hash' => $payloadHash, 'submitted_by' => $request->user()->id,
            ]);
            $this->event($leave, 'SUBMITTED', null, 'PENDING', null, $request->user()->id);
            $this->audit->record($request, $request->user(), $companyId, 'leave_submitted', 'leave', $leave, null, ['units' => $units, 'status' => 'PENDING']);
            $this->notifyApprovers($request, $leave);

            return $leave;
        }, 3);
    }

    public function transition(Request $request, string $companyId, string $leaveId, string $action, ?string $reason = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $companyId, $leaveId, $action, $reason): LeaveRequest {
            $reference = LeaveRequest::query()->where('company_id', $companyId)->findOrFail($leaveId);
            $employee = Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($reference->employee_id);
            $leave = LeaveRequest::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($leaveId);
            $selfAction = in_array($action, ['CANCEL_PENDING', 'REQUEST_CANCELLATION'], true);
            if ($selfAction) {
                if ($leave->submitted_by !== $request->user()->id || in_array(mb_strtolower($employee->status), ['resigned', 'terminated'], true)) {
                    throw new PlatformException('LEAVE_OWNER_REQUIRED', 'Only an active employee owner may request cancellation.', 403);
                }
            } elseif (CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
                ->where('is_active', true)->where('employee_id', $leave->employee_id)->exists()) {
                throw new PlatformException('LEAVE_SELF_APPROVAL_DENIED', 'An approver cannot decide their own leave.', 403);
            }
            $timezone = (string) Company::query()->whereKey($companyId)->value('timezone');
            $future = $leave->start_date->toDateString() > CarbonImmutable::now($timezone)->toDateString();
            [$from, $to] = match ($action) {
                'APPROVE' => ['PENDING', 'APPROVED'], 'REJECT' => ['PENDING', 'REJECTED'],
                'CANCEL_PENDING' => ['PENDING', 'CANCELLED'],
                'REQUEST_CANCELLATION' => ['APPROVED', 'CANCELLATION_PENDING'],
                'APPROVE_CANCELLATION' => ['CANCELLATION_PENDING', 'CANCELLED'],
                'REJECT_CANCELLATION' => ['CANCELLATION_PENDING', 'APPROVED'],
                default => throw new PlatformException('LEAVE_ACTION_INVALID', 'Unsupported leave action.', 422),
            };
            if ($leave->status !== $from) {
                if ($leave->status === $to && $leave->events()->where('action', $action)->where('reason', $reason)->exists()) {
                    return $leave;
                }
                throw new PlatformException('LEAVE_ALREADY_DECIDED', 'The request has already changed state.', 409);
            }
            if (in_array($action, ['REQUEST_CANCELLATION', 'APPROVE_CANCELLATION'], true) && ! $future) {
                throw new PlatformException('LEAVE_CANCELLATION_TOO_LATE', 'Approved leave that has started cannot be cancelled.', 409);
            }
            if (in_array($action, ['REJECT', 'REJECT_CANCELLATION'], true) && ($reason === null || mb_strlen(trim($reason)) < 5)) {
                throw new PlatformException('LEAVE_REASON_REQUIRED', 'A rejection reason of at least five characters is required.', 422);
            }
            $leave->forceFill(['status' => $to])->save();
            $this->event($leave, $action, $from, $to, $reason, $request->user()->id);
            $this->audit->record($request, $request->user(), $companyId, 'leave_'.mb_strtolower($action), 'leave', $leave, ['status' => $from], ['status' => $to]);
            if ($action === 'REQUEST_CANCELLATION') {
                $this->notifyApprovers($request, $leave, true);
            }
            if (! $selfAction) {
                $this->notifications->create($companyId, $leave->submitted_by, 'leave.'.mb_strtolower($action), 'Leave request updated', 'Your leave request status is '.$to.'.', ['leave_request_id' => $leave->id], '/employee/leaves');
            }

            return $leave;
        }, 3);
    }

    public function adjust(Request $request, LeaveEntitlement $entitlement, int $delta, string $reason, string $key): LeaveEntitlementAdjustment
    {
        $this->assertKey($key);
        $payloadHash = hash('sha256', json_encode([$delta, $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $entitlement, $delta, $reason, $key, $payloadHash): LeaveEntitlementAdjustment {
            Employee::query()->where('company_id', $entitlement->company_id)->lockForUpdate()->findOrFail($entitlement->employee_id);
            $entitlement = LeaveEntitlement::query()->where('company_id', $entitlement->company_id)->lockForUpdate()->findOrFail($entitlement->id);
            $prior = LeaveEntitlementAdjustment::query()->where('company_id', $entitlement->company_id)
                ->where('leave_entitlement_id', $entitlement->id)->where('request_key_hash', hash('sha256', $key))->first();
            if ($prior !== null) {
                if ($prior->payload_hash !== $payloadHash) {
                    throw new PlatformException('IDEMPOTENCY_PAYLOAD_CONFLICT', 'This key belongs to a different entitlement adjustment.', 409);
                }

                return $prior;
            }
            if ($this->balance($entitlement)['available_units'] + $delta < 0) {
                throw new PlatformException('LEAVE_ADJUSTMENT_OVERCONSUMES', 'The adjustment would over-consume entitlement.', 409);
            }
            $adjustment = LeaveEntitlementAdjustment::query()->create([
                'company_id' => $entitlement->company_id, 'leave_entitlement_id' => $entitlement->id,
                'delta_units' => $delta, 'reason' => $reason, 'request_key_hash' => hash('sha256', $key),
                'payload_hash' => $payloadHash, 'created_by' => $request->user()->id, 'created_at' => now('UTC'),
            ]);
            $this->audit->record($request, $request->user(), $entitlement->company_id, 'leave_entitlement_adjusted', 'leave', $adjustment, null, ['delta_units' => $delta, 'reason' => $reason]);

            return $adjustment;
        }, 3);
    }

    private function assertKey(string $key): void
    {
        if ($key === '' || mb_strlen($key) > 200) {
            throw new PlatformException('IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.', 422);
        }
    }

    private function event(LeaveRequest $leave, string $action, ?string $from, string $to, ?string $reason, int $actorId): void
    {
        LeaveRequestEvent::query()->create(['company_id' => $leave->company_id, 'leave_request_id' => $leave->id,
            'action' => $action, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason,
            'actor_id' => $actorId, 'created_at' => now('UTC')]);
    }

    private function notifyApprovers(Request $request, LeaveRequest $leave, bool $cancellation = false): void
    {
        CompanyUser::query()->where('company_id', $leave->company_id)->where('is_active', true)
            ->where('user_id', '!=', $request->user()->id)->with('user')->get()->each(function (CompanyUser $membership) use ($leave, $cancellation): void {
                if ($membership->user?->hasCompanyPermission($leave->company_id, 'leave.approve')) {
                    $this->notifications->create($leave->company_id, $membership->user_id,
                        $cancellation ? 'leave.cancellation_requested' : 'leave.submitted',
                        $cancellation ? 'Leave cancellation requested' : 'Leave approval requested',
                        $cancellation ? 'A leave cancellation awaits review.' : 'A leave request awaits review.',
                        ['leave_request_id' => $leave->id], '/hrm/leave');
                }
            });
    }
}
