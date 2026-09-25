<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use App\Exceptions\OutreachException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outreach\EnrollOutreachRequest;
use App\Http\Resources\OutreachEnrollmentResource;
use App\Models\OutreachEnrollment;
use App\Models\OutreachSequence;
use App\Services\AuditService;
use App\Services\Outreach\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OutreachEnrollmentController extends Controller
{
    use AuthorizesOutreachRequests;

    public function __construct(private readonly EnrollmentService $enrollments, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'outreach.view');
        $query = OutreachEnrollment::query()->where('company_id', $this->companyId($request))->with('sequence')->withCount('messages');
        foreach (['sequence_id', 'status', 'recipient_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return OutreachEnrollmentResource::collection($query->orderByDesc('enrolled_at')->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(EnrollOutreachRequest $request, string $sequence): JsonResponse
    {
        $model = OutreachSequence::query()->where('company_id', $this->companyId($request))->findOrFail($sequence);
        $result = $this->enrollments->enroll($this->companyId($request), $request->user()->id, $model, $request->validated());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'recipients_enrolled', 'outreach', $model, null, ['enrolled' => count($result['enrolled']), 'failed' => count($result['failures'])]);

        return response()->json(['enrolled' => OutreachEnrollmentResource::collection(collect($result['enrolled'])), 'failures' => $result['failures']], 201);
    }

    public function transition(Request $request, string $enrollment): OutreachEnrollmentResource
    {
        $this->authorizePermission($request, 'outreach.send');
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,PAUSED,CANCELLED']]);
        $model = OutreachEnrollment::query()->where('company_id', $this->companyId($request))->findOrFail($enrollment);
        $allowed = ['ACTIVE' => ['PAUSED', 'CANCELLED'], 'PAUSED' => ['ACTIVE', 'CANCELLED']];
        if (! in_array($data['status'], $allowed[$model->status] ?? [], true)) {
            throw new OutreachException('INVALID_ENROLLMENT_TRANSITION', 'This enrollment transition is not allowed.', 409);
        } $model->update(['status' => $data['status'], 'ended_at' => $data['status'] === 'CANCELLED' ? now() : null, 'next_action_at' => $data['status'] === 'CANCELLED' ? null : $model->next_action_at]);
        if ($data['status'] === 'CANCELLED') {
            $model->messages()->whereIn('state', ['SCHEDULED', 'QUEUED'])->update(['state' => 'CANCELLED', 'cancelled_at' => now()]);
        } $this->audit->record($request, $request->user(), $this->companyId($request), 'enrollment_transitioned', 'outreach', $model, null, ['status' => $model->status]);

        return new OutreachEnrollmentResource($model->fresh()->load('sequence')->loadCount('messages'));
    }
}
