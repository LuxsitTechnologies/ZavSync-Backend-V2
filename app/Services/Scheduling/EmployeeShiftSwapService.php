<?php

namespace App\Services\Scheduling;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeRota;
use App\Models\EmployeeRotaSlot;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftSwap;
use App\Models\EmployeeShiftSwapEvent;
use App\Services\AuditService;
use App\Services\Platform\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeShiftSwapService
{
    public function __construct(
        private readonly EmployeeScheduleService $schedule,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function request(Request $request, string $companyId, Employee $requester, string $fromId, string $toId, string $reason, string $keyHash): EmployeeShiftSwap
    {
        $fingerprint = hash('sha256', json_encode([$fromId, $toId, $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $companyId, $requester, $fromId, $toId, $reason, $keyHash, $fingerprint): EmployeeShiftSwap {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = EmployeeShiftSwap::query()->where('company_id', $companyId)->where('requester_employee_id', $requester->id)
                ->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                $this->schedule->assertFingerprint($prior->create_payload_hash, $fingerprint);

                return $prior;
            }
            $this->schedule->assertCurrent($requester);
            if ($fromId === $toId) {
                throw new PlatformException('SWAP_SAME_ASSIGNMENT', 'Select a different assignment to exchange.', 422);
            }
            [$from, $to] = $this->lockedPair($companyId, $fromId, $toId);
            if ($from->employee_id !== $requester->id) {
                abort(404);
            }
            $target = Employee::query()->where('company_id', $companyId)->findOrFail($to->employee_id);
            $this->schedule->assertCurrent($target);
            if ($target->id === $requester->id) {
                throw new PlatformException('SWAP_SAME_EMPLOYEE', 'A shift swap requires another employee.', 422);
            }
            [$fromSlot, $toSlot] = $this->eligibleSlots($companyId, $from, $to);
            $this->schedule->assertNoConflict($companyId, $target->id, $fromSlot->shift_date->toDateString(),
                $fromSlot->start_time, $fromSlot->end_time, [$from->id, $to->id]);
            $this->schedule->assertNoConflict($companyId, $requester->id, $toSlot->shift_date->toDateString(),
                $toSlot->start_time, $toSlot->end_time, [$from->id, $to->id]);
            if (EmployeeShiftSwap::query()->where('company_id', $companyId)->whereIn('status', ['PENDING_TARGET', 'PENDING_ADMIN'])
                ->where(function ($query) use ($fromId, $toId): void {
                    $query->whereIn('from_assignment_id', [$fromId, $toId])->orWhereIn('to_assignment_id', [$fromId, $toId]);
                })->exists()) {
                throw new PlatformException('SWAP_ASSIGNMENT_PENDING', 'One of these assignments already has an active swap request.', 409);
            }
            $swap = EmployeeShiftSwap::query()->create(['company_id' => $companyId, 'requester_employee_id' => $requester->id,
                'target_employee_id' => $target->id, 'from_assignment_id' => $fromId, 'to_assignment_id' => $toId,
                'from_assignment_version' => $from->version, 'to_assignment_version' => $to->version,
                'reason' => $reason, 'created_by' => $request->user()->id]);
            $swap->forceFill(['create_request_key_hash' => $keyHash, 'create_payload_hash' => $fingerprint])->save();
            $swap->refresh();
            $this->event($swap, 'REQUESTED', $request);
            $this->audit->record($request, $request->user(), $companyId, 'employee_shift_swap_requested', 'employee_shift_swaps', $swap);
            $this->notifyEmployee($companyId, $target->id, $request->user()->id, 'schedule.swap.requested',
                'Shift swap response needed', 'A colleague has requested a shift swap.', $swap->id);

            return $swap;
        });
    }

    public function respond(Request $request, string $companyId, Employee $target, string $swapId, int $version, bool $accept): EmployeeShiftSwap
    {
        return DB::transaction(function () use ($request, $companyId, $target, $swapId, $version, $accept): EmployeeShiftSwap {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $swap = EmployeeShiftSwap::query()->where('company_id', $companyId)->where('target_employee_id', $target->id)
                ->lockForUpdate()->findOrFail($swapId);
            if ($swap->status === ($accept ? 'PENDING_ADMIN' : 'DECLINED')) {
                return $swap;
            }
            $this->schedule->assertCurrent($target);
            $this->schedule->assertVersion($swap->version, $version);
            if ($swap->status !== 'PENDING_TARGET') {
                throw new PlatformException('SWAP_NOT_AWAITING_TARGET', 'This swap no longer awaits the target employee.', 409);
            }
            $swap->forceFill(['status' => $accept ? 'PENDING_ADMIN' : 'DECLINED', 'version' => $swap->version + 1,
                'target_accepted_at' => $accept ? now() : null])->save();
            $this->event($swap, $accept ? 'TARGET_ACCEPTED' : 'TARGET_DECLINED', $request);
            $this->audit->record($request, $request->user(), $companyId, $accept ? 'employee_shift_swap_target_accepted' : 'employee_shift_swap_target_declined',
                'employee_shift_swaps', $swap);
            $this->notifyEmployee($companyId, $swap->requester_employee_id, $request->user()->id, 'schedule.swap.responded',
                'Shift swap updated', $accept ? 'The other employee accepted the requested swap.' : 'The other employee declined the requested swap.', $swap->id);

            return $swap;
        });
    }

    public function decide(Request $request, string $companyId, string $swapId, int $version, bool $approve, ?string $reason): EmployeeShiftSwap
    {
        return DB::transaction(function () use ($request, $companyId, $swapId, $version, $approve, $reason): EmployeeShiftSwap {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $swap = EmployeeShiftSwap::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($swapId);
            if ($swap->status === ($approve ? 'APPROVED' : 'REJECTED')) {
                return $swap;
            }
            $this->schedule->assertVersion($swap->version, $version);
            if ($swap->status !== 'PENDING_ADMIN') {
                throw new PlatformException('SWAP_NOT_READY_FOR_DECISION', 'The target employee must accept before an administrative decision.', 409);
            }
            $administrator = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
                ->where('is_active', true)->firstOrFail();
            if (in_array($administrator->employee_id, [$swap->requester_employee_id, $swap->target_employee_id], true)) {
                throw new PlatformException('SWAP_SELF_APPROVAL_FORBIDDEN', 'A swap participant cannot approve or reject their own request.', 403);
            }
            if ($approve) {
                [$from, $to] = $this->lockedPair($companyId, $swap->from_assignment_id, $swap->to_assignment_id);
                if ($from->version !== $swap->from_assignment_version || $to->version !== $swap->to_assignment_version
                    || $from->employee_id !== $swap->requester_employee_id || $to->employee_id !== $swap->target_employee_id) {
                    throw new PlatformException('SWAP_ASSIGNMENT_STALE', 'An assignment changed after this swap was requested.', 409);
                }
                [$fromSlot, $toSlot] = $this->eligibleSlots($companyId, $from, $to);
                $requester = Employee::query()->where('company_id', $companyId)->findOrFail($swap->requester_employee_id);
                $target = Employee::query()->where('company_id', $companyId)->findOrFail($swap->target_employee_id);
                $this->schedule->assertCurrent($requester);
                $this->schedule->assertCurrent($target);
                $exclusions = [$from->id, $to->id];
                $this->schedule->assertNoConflict($companyId, $target->id, $fromSlot->shift_date->toDateString(),
                    $fromSlot->start_time, $fromSlot->end_time, $exclusions);
                $this->schedule->assertNoConflict($companyId, $requester->id, $toSlot->shift_date->toDateString(),
                    $toSlot->start_time, $toSlot->end_time, $exclusions);
                $this->schedule->assertVersion($from->version, $from->version);
                $this->schedule->assertVersion($to->version, $to->version);
                $from->forceFill(['employee_id' => $target->id, 'version' => $from->version + 1])->save();
                $to->forceFill(['employee_id' => $requester->id, 'version' => $to->version + 1])->save();
            }
            $swap->forceFill(['status' => $approve ? 'APPROVED' : 'REJECTED', 'version' => $swap->version + 1,
                'decision_reason' => $reason, 'decided_at' => now(), 'decided_by' => $request->user()->id])->save();
            $this->event($swap, $approve ? 'APPROVED' : 'REJECTED', $request, $reason);
            $this->audit->record($request, $request->user(), $companyId, $approve ? 'employee_shift_swap_approved' : 'employee_shift_swap_rejected',
                'employee_shift_swaps', $swap);
            foreach ([$swap->requester_employee_id, $swap->target_employee_id] as $recipientEmployeeId) {
                $this->notifyEmployee($companyId, $recipientEmployeeId, $request->user()->id, 'schedule.swap.decided',
                    'Shift swap decision', $approve ? 'The shift swap was approved.' : 'The shift swap was rejected.', $swap->id);
            }

            return $swap;
        });
    }

    /** @return array{EmployeeShiftAssignment, EmployeeShiftAssignment} */
    private function lockedPair(string $companyId, string $fromId, string $toId): array
    {
        $locked = EmployeeShiftAssignment::query()->where('company_id', $companyId)->whereIn('id', [$fromId, $toId])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$locked->get($fromId) ?? abort(404), $locked->get($toId) ?? abort(404)];
    }

    /** @return array{EmployeeRotaSlot, EmployeeRotaSlot} */
    private function eligibleSlots(string $companyId, EmployeeShiftAssignment $from, EmployeeShiftAssignment $to): array
    {
        $fromSlot = EmployeeRotaSlot::query()->where('company_id', $companyId)->findOrFail($from->employee_rota_slot_id);
        $toSlot = EmployeeRotaSlot::query()->where('company_id', $companyId)->findOrFail($to->employee_rota_slot_id);
        foreach ([$fromSlot, $toSlot] as $slot) {
            $this->schedule->assertFutureDate($companyId, $slot->shift_date->toDateString());
            $rota = EmployeeRota::query()->where('company_id', $companyId)->findOrFail($slot->employee_rota_id);
            if ($rota->status !== 'PUBLISHED') {
                throw new PlatformException('SWAP_ROTA_NOT_PUBLISHED', 'Only published shifts may be swapped.', 409);
            }
        }

        return [$fromSlot, $toSlot];
    }

    private function event(EmployeeShiftSwap $swap, string $type, Request $request, ?string $reason = null): void
    {
        EmployeeShiftSwapEvent::query()->create(['company_id' => $swap->company_id, 'employee_shift_swap_id' => $swap->id,
            'event_type' => $type, 'swap_version' => $swap->version, 'actor_id' => $request->user()->id,
            'reason' => $reason, 'occurred_at' => now()]);
    }

    private function notifyEmployee(string $companyId, string $employeeId, int $actorId, string $type, string $title, string $message, string $swapId): void
    {
        $recipientId = CompanyUser::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('is_active', true)->value('user_id');
        if ($recipientId !== null && (int) $recipientId !== $actorId) {
            $this->notifications->createInApp($companyId, (int) $recipientId, $type, $title, $message, ['swap_id' => $swapId]);
        }
    }
}
