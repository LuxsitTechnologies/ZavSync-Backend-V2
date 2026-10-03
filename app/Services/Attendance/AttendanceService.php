<?php

namespace App\Services\Attendance;

use App\Exceptions\PlatformException;
use App\Models\AttendanceBreak;
use App\Models\AttendanceIdempotency;
use App\Models\AttendanceSession;
use App\Models\Company;
use App\Models\Employee;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array<string, mixed> */
    public function status(string $companyId, string $employeeId): array
    {
        $timezone = (string) Company::query()->whereKey($companyId)->value('timezone');
        $workDate = CarbonImmutable::now('UTC')->setTimezone($timezone)->toDateString();
        $session = AttendanceSession::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('active_employee_id', $employeeId)->with('breaks')->first();
        if ($session === null) {
            $session = AttendanceSession::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
                ->where('work_date', $workDate)->where('state', 'CLOCKED_OUT')
                ->orderByDesc('clock_out_at')->with('breaks')->first();
        }

        return [
            'state' => $session?->state ?? 'NOT_CLOCKED_IN',
            'timezone' => $session?->timezone ?? $timezone,
            'work_date' => $session?->work_date->format('Y-m-d') ?? $workDate,
            'session' => $session === null ? null : $this->present($session),
            'allowed_actions' => match ($session?->state) {
                'CLOCKED_IN' => ['CLOCK_OUT', 'BREAK_START'],
                'ON_BREAK' => ['BREAK_END'],
                default => ['CLOCK_IN'],
            },
        ];
    }

    /** @return array<string, mixed> */
    public function transition(Request $request, string $companyId, string $employeeId, string $action, string $key): array
    {
        if ($key === '' || mb_strlen($key) > 200) {
            throw new PlatformException('IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.', 422);
        }

        return DB::transaction(function () use ($request, $companyId, $employeeId, $action, $key): array {
            $employee = Employee::query()->where('company_id', $companyId)->whereKey($employeeId)->lockForUpdate()->firstOrFail();
            $keyHash = hash('sha256', $key);
            $payloadHash = hash('sha256', $action.'|{}');
            $existing = AttendanceIdempotency::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
                ->where('key_hash', $keyHash)->first();
            if ($existing !== null) {
                if ($existing->action !== $action || $existing->payload_hash !== $payloadHash) {
                    throw new PlatformException('IDEMPOTENCY_PAYLOAD_CONFLICT', 'This idempotency key was used for a different attendance action.', 409);
                }

                return $existing->response_snapshot;
            }
            if (in_array(mb_strtolower($employee->status), ['terminated', 'resigned'], true)) {
                throw new PlatformException('EMPLOYEE_ATTENDANCE_INACTIVE', 'Former employees cannot create attendance events.', 403);
            }

            $session = AttendanceSession::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
                ->where('active_employee_id', $employeeId)->lockForUpdate()->first();
            $instant = CarbonImmutable::now('UTC')->startOfSecond();
            $break = null;
            if ($action === 'CLOCK_IN') {
                if ($session !== null) {
                    throw new PlatformException('ATTENDANCE_ALREADY_CLOCKED_IN', 'An attendance session is already open.', 409);
                }
                $timezone = Company::query()->whereKey($companyId)->value('timezone');
                $session = AttendanceSession::query()->create([
                    'company_id' => $companyId, 'employee_id' => $employeeId, 'active_employee_id' => $employeeId,
                    'work_date' => $instant->setTimezone($timezone)->toDateString(), 'timezone' => $timezone,
                    'state' => 'CLOCKED_IN', 'clock_in_at' => $instant, 'created_by' => $request->user()->id,
                ]);
            } elseif ($action === 'CLOCK_OUT') {
                if ($session === null || $session->state !== 'CLOCKED_IN') {
                    throw new PlatformException('ATTENDANCE_INVALID_TRANSITION', 'Clock-out requires an open session with no open break.', 409);
                }
                $elapsed = $instant->getTimestamp() - $session->clock_in_at->getTimestamp();
                $worked = $elapsed - $session->completed_break_seconds;
                if ($elapsed <= 0 || $worked < 0 || $elapsed > 4294967295 || $worked > 4294967295) {
                    throw new PlatformException('ATTENDANCE_DURATION_INVALID', 'Attendance duration is outside the supported range.', 409);
                }
                $session->forceFill(['state' => 'CLOCKED_OUT', 'clock_out_at' => $instant, 'worked_seconds' => $worked, 'active_employee_id' => null])->save();
            } elseif ($action === 'BREAK_START') {
                if ($session === null || $session->state !== 'CLOCKED_IN') {
                    throw new PlatformException('ATTENDANCE_INVALID_TRANSITION', 'A break requires a clocked-in session.', 409);
                }
                $lastBreakEnd = AttendanceBreak::query()->where('company_id', $companyId)
                    ->where('attendance_session_id', $session->id)->max('ended_at');
                if ($instant->getTimestamp() <= $session->clock_in_at->getTimestamp()
                    || ($lastBreakEnd !== null && $instant->getTimestamp() < CarbonImmutable::parse($lastBreakEnd, 'UTC')->getTimestamp())) {
                    throw new PlatformException('ATTENDANCE_DURATION_INVALID', 'Break start is outside the supported session chronology.', 409);
                }
                $break = AttendanceBreak::query()->create([
                    'company_id' => $companyId, 'attendance_session_id' => $session->id,
                    'active_session_id' => $session->id, 'started_at' => $instant,
                ]);
                $session->forceFill(['state' => 'ON_BREAK'])->save();
            } elseif ($action === 'BREAK_END') {
                if ($session === null || $session->state !== 'ON_BREAK') {
                    throw new PlatformException('ATTENDANCE_INVALID_TRANSITION', 'No open break is available to end.', 409);
                }
                $break = AttendanceBreak::query()->where('company_id', $companyId)->where('attendance_session_id', $session->id)
                    ->where('active_session_id', $session->id)->lockForUpdate()->firstOrFail();
                $seconds = $instant->getTimestamp() - $break->started_at->getTimestamp();
                $total = $session->completed_break_seconds + $seconds;
                if ($seconds <= 0 || $total > 4294967295) {
                    throw new PlatformException('ATTENDANCE_DURATION_INVALID', 'Break duration is outside the supported range.', 409);
                }
                $break->forceFill(['ended_at' => $instant, 'duration_seconds' => $seconds, 'active_session_id' => null])->save();
                $session->forceFill(['state' => 'CLOCKED_IN', 'completed_break_seconds' => $total])->save();
            } else {
                throw new PlatformException('ATTENDANCE_ACTION_INVALID', 'The requested attendance action is unsupported.', 422);
            }

            $result = $this->present($session->load('breaks'));
            AttendanceIdempotency::query()->create([
                'company_id' => $companyId, 'employee_id' => $employeeId, 'key_hash' => $keyHash,
                'payload_hash' => $payloadHash, 'action' => $action, 'response_snapshot' => $result,
            ]);
            $this->audit->record($request, $request->user(), $companyId, mb_strtolower($action), 'attendance', $session, null, [
                'session_id' => $session->id, 'state' => $session->state, 'break_id' => $break?->id,
            ]);

            return $result;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function present(AttendanceSession $session): array
    {
        $session->loadMissing(['breaks', 'revisions']);
        $original = [
            'clock_in_at' => $session->clock_in_at->toIso8601String(),
            'clock_out_at' => $session->clock_out_at?->toIso8601String(),
            'breaks' => $session->breaks->sortBy('started_at')->map(fn (AttendanceBreak $break): array => [
                'id' => $break->id, 'started_at' => $break->started_at->toIso8601String(),
                'ended_at' => $break->ended_at?->toIso8601String(), 'duration_seconds' => $break->duration_seconds,
            ])->values()->all(),
            'break_seconds' => $session->completed_break_seconds,
            'worked_seconds' => $session->worked_seconds,
        ];
        $latest = $session->revisions->sortByDesc('revision_number')->first();

        return [
            'id' => $session->id, 'work_date' => $session->work_date->format('Y-m-d'),
            'timezone' => $session->timezone, 'state' => $session->state,
            'original' => $original, 'effective' => $latest?->effective_snapshot ?? $original,
            'corrected' => $latest !== null, 'revision_number' => $latest?->revision_number ?? 0,
            'provenance' => $session->provenance,
        ];
    }
}
