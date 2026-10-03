<?php

namespace App\Services\Attendance;

use App\Exceptions\PlatformException;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceRevision;
use App\Models\AttendanceSession;
use App\Models\Employee;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceCorrectionService
{
    public function __construct(private readonly AttendanceService $attendance, private readonly AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function submit(Request $request, string $companyId, string $employeeId, string $sessionId, array $data, string $key, bool $administrative = false): AttendanceCorrectionRequest
    {
        if ($key === '' || mb_strlen($key) > 200) {
            throw new PlatformException('IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.', 422);
        }

        return DB::transaction(function () use ($request, $companyId, $employeeId, $sessionId, $data, $key, $administrative): AttendanceCorrectionRequest {
            Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($employeeId);
            $session = AttendanceSession::query()->where('company_id', $companyId)->where('employee_id', $employeeId)->lockForUpdate()->findOrFail($sessionId);
            $keyHash = hash('sha256', $key);
            $prior = AttendanceCorrectionRequest::query()->where('company_id', $companyId)->where('employee_id', $employeeId)->where('request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                if ($prior->attendance_session_id !== $sessionId || $prior->kind !== ($administrative ? 'ADMIN_INTERVENTION' : 'EMPLOYEE_REQUEST')
                    || $prior->reason !== $data['reason'] || $prior->proposed_snapshot !== $this->normalize($session, $data)) {
                    throw new PlatformException('IDEMPOTENCY_PAYLOAD_CONFLICT', 'This idempotency key was used for a different attendance correction.', 409);
                }

                return $prior;
            }
            if ($session->state !== 'CLOCKED_OUT') {
                throw new PlatformException('ATTENDANCE_SESSION_OPEN', 'Corrections require a closed attendance session.', 409);
            }
            $before = $this->attendance->present($session)['effective'];
            $proposed = $this->normalize($session, $data);
            $correction = AttendanceCorrectionRequest::query()->create([
                'company_id' => $companyId, 'employee_id' => $employeeId, 'attendance_session_id' => $sessionId,
                'kind' => $administrative ? 'ADMIN_INTERVENTION' : 'EMPLOYEE_REQUEST',
                'status' => $administrative ? 'APPROVED' : 'PENDING', 'request_key_hash' => $keyHash,
                'original_snapshot' => $before, 'proposed_snapshot' => $proposed,
                'reason' => $data['reason'], 'submitted_by' => $request->user()->id,
                'decided_by' => $administrative ? $request->user()->id : null,
                'decided_at' => $administrative ? now('UTC') : null,
            ]);
            if ($administrative) {
                $this->revision($request, $session, $correction, $before);
            }
            $this->audit->record($request, $request->user(), $companyId,
                $administrative ? 'attendance_intervention_approved' : 'attendance_correction_requested',
                'attendance', $correction, $before, $proposed);

            return $correction;
        }, 3);
    }

    public function decide(Request $request, string $companyId, string $correctionId, bool $approve, string $reason): AttendanceCorrectionRequest
    {
        return DB::transaction(function () use ($request, $companyId, $correctionId, $approve, $reason): AttendanceCorrectionRequest {
            $correction = AttendanceCorrectionRequest::query()->where('company_id', $companyId)->findOrFail($correctionId);
            Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($correction->employee_id);
            $session = AttendanceSession::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($correction->attendance_session_id);
            $correction = AttendanceCorrectionRequest::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($correctionId);
            if ($correction->status !== 'PENDING') {
                if ($correction->status === ($approve ? 'APPROVED' : 'REJECTED') && $correction->decision_reason === $reason) {
                    return $correction;
                }
                throw new PlatformException('ATTENDANCE_CORRECTION_DECIDED', 'This correction has already been decided differently.', 409);
            }
            $before = $this->attendance->present($session)['effective'];
            if ($approve && $before !== $correction->original_snapshot) {
                throw new PlatformException('ATTENDANCE_CORRECTION_STALE', 'Attendance changed after this correction was requested.', 409);
            }
            $correction->forceFill([
                'status' => $approve ? 'APPROVED' : 'REJECTED', 'decision_reason' => $reason,
                'decided_by' => $request->user()->id, 'decided_at' => now('UTC'),
            ])->save();
            if ($approve) {
                $this->revision($request, $session, $correction, $before);
            }
            $this->audit->record($request, $request->user(), $companyId,
                $approve ? 'attendance_correction_approved' : 'attendance_correction_rejected',
                'attendance', $correction, $before, $approve ? $correction->proposed_snapshot : null);

            return $correction;
        }, 3);
    }

    /** @param array<string, mixed> $before */
    private function revision(Request $request, AttendanceSession $session, AttendanceCorrectionRequest $correction, array $before): void
    {
        $number = (int) AttendanceRevision::query()->where('attendance_session_id', $session->id)->max('revision_number') + 1;
        AttendanceRevision::query()->create([
            'company_id' => $session->company_id, 'attendance_session_id' => $session->id,
            'attendance_correction_request_id' => $correction->id, 'revision_number' => $number,
            'before_snapshot' => $before, 'effective_snapshot' => $correction->proposed_snapshot,
            'created_by' => $request->user()->id, 'created_at' => now('UTC'),
        ]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalize(AttendanceSession $session, array $data): array
    {
        $in = $this->instant($data['clock_in_at']);
        $out = $this->instant($data['clock_out_at']);
        $elapsed = $out->getTimestamp() - $in->getTimestamp();
        if ($elapsed <= 0 || $elapsed > 4294967295 || $in->setTimezone($session->timezone)->toDateString() !== $session->work_date->format('Y-m-d')) {
            throw new PlatformException('ATTENDANCE_CORRECTION_INVALID', 'Corrected times must be ordered and retain the session work date.', 422);
        }

        $breaks = [];
        $breakSeconds = 0;
        $previousEnd = $in->getTimestamp();
        foreach ($data['breaks'] as $item) {
            $start = $this->instant($item['started_at']);
            $end = $this->instant($item['ended_at']);
            $startSeconds = $start->getTimestamp();
            $endSeconds = $end->getTimestamp();
            if ($startSeconds < $previousEnd || $endSeconds <= $startSeconds || $endSeconds > $out->getTimestamp()) {
                throw new PlatformException('ATTENDANCE_CORRECTION_INVALID', 'Corrected breaks must be ordered, non-overlapping and inside the session.', 422);
            }
            $seconds = $endSeconds - $startSeconds;
            $breakSeconds += $seconds;
            $previousEnd = $endSeconds;
            $breaks[] = ['started_at' => $start->toIso8601String(), 'ended_at' => $end->toIso8601String(), 'duration_seconds' => $seconds];
        }

        return [
            'clock_in_at' => $in->toIso8601String(), 'clock_out_at' => $out->toIso8601String(),
            'breaks' => $breaks, 'break_seconds' => $breakSeconds, 'worked_seconds' => $elapsed - $breakSeconds,
        ];
    }

    private function instant(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new PlatformException('ATTENDANCE_TIMEZONE_REQUIRED', 'Correction timestamps require seconds and an explicit UTC offset.', 422);
        }

        return CarbonImmutable::parse($value)->setTimezone('UTC')->startOfSecond();
    }
}
