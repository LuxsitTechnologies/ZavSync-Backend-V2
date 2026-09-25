<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outreach\OutreachSequenceRequest;
use App\Http\Resources\OutreachSequenceResource;
use App\Models\OutreachSequence;
use App\Services\AuditService;
use App\Services\Outreach\SequenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OutreachSequenceController extends Controller
{
    use AuthorizesOutreachRequests;

    public function __construct(private readonly SequenceService $sequences, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'outreach.view');
        $query = OutreachSequence::query()->where('company_id', $this->companyId($request))->with('sendingIdentity')->withCount(['enrollments', 'messages']);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->upper()->toString());
        }

        return OutreachSequenceResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(OutreachSequenceRequest $request): OutreachSequenceResource
    {
        $sequence = $this->sequences->create($this->companyId($request), $request->user()->id, $request->validated());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'sequence_created', 'outreach', $sequence, null, $sequence->toArray());

        return new OutreachSequenceResource($sequence);
    }

    public function show(Request $request, string $sequence): OutreachSequenceResource
    {
        $this->authorizePermission($request, 'outreach.view');

        return new OutreachSequenceResource($this->sequence($request, $sequence)->load(['steps.template', 'sendingIdentity'])->loadCount(['enrollments', 'messages']));
    }

    public function update(OutreachSequenceRequest $request, string $sequence): OutreachSequenceResource
    {
        $model = $this->sequence($request, $sequence);
        $old = $model->load('steps')->toArray();
        $model = $this->sequences->update($model, $request->user()->id, $request->validated());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'sequence_updated', 'outreach', $model, $old, $model->toArray());

        return new OutreachSequenceResource($model);
    }

    public function transition(Request $request, string $sequence): OutreachSequenceResource
    {
        $this->authorizePermission($request, 'outreach.sequences.manage');
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,PAUSED,COMPLETED,ARCHIVED']]);
        $model = $this->sequences->transition($this->sequence($request, $sequence), $data['status'], $this->companyId($request), $request->user()->id);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'sequence_transitioned', 'outreach', $model, null, ['status' => $model->status]);

        return new OutreachSequenceResource($model);
    }

    private function sequence(Request $request, string $id): OutreachSequence
    {
        return OutreachSequence::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }
}
