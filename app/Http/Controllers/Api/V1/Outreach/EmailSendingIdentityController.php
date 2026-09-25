<?php

namespace App\Http\Controllers\Api\V1\Outreach;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outreach\EmailSendingIdentityRequest;
use App\Http\Resources\EmailSendingIdentityResource;
use App\Models\EmailSendingIdentity;
use App\Services\AuditService;
use App\Services\Outreach\SendingIdentityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmailSendingIdentityController extends Controller
{
    use AuthorizesOutreachRequests;

    public function __construct(private readonly SendingIdentityService $identities, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'outreach.view');

        return EmailSendingIdentityResource::collection(EmailSendingIdentity::query()->where('company_id', $this->companyId($request))->with('connection')->orderByDesc('is_default')->orderBy('from_email')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(EmailSendingIdentityRequest $request): EmailSendingIdentityResource
    {
        $identity = $this->identities->create($this->companyId($request), $request->user()->id, $request->validated());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'sending_identity_created', 'outreach', $identity, null, $identity->toArray());

        return new EmailSendingIdentityResource($identity->load('connection'));
    }

    public function update(EmailSendingIdentityRequest $request, string $identity): EmailSendingIdentityResource
    {
        $model = $this->identity($request, $identity);
        $old = $model->toArray();
        $model = $this->identities->update($model, $request->user()->id, $request->validated());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'sending_identity_updated', 'outreach', $model, $old, $model->toArray());

        return new EmailSendingIdentityResource($model->load('connection'));
    }

    private function identity(Request $request, string $id): EmailSendingIdentity
    {
        return EmailSendingIdentity::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }
}
