<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Crm\AttachCrmTagsRequest;
use App\Http\Requests\Api\V1\Crm\StoreCrmTagRequest;
use App\Http\Resources\CrmTagResource;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmTag;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CrmTagController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.view'), 403);

        return CrmTagResource::collection(CrmTag::query()->where('company_id', $this->companyId($request))->orderBy('name')->get());
    }

    public function store(StoreCrmTagRequest $request): CrmTagResource
    {
        $tag = CrmTag::query()->create(['company_id' => $this->companyId($request), 'name' => trim($request->validated('name')), 'normalized_name' => mb_strtolower(trim($request->validated('name'))), 'color' => $request->validated('color'), 'created_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'create', 'crm_tag', $tag, null, $tag->toArray());

        return new CrmTagResource($tag);
    }

    public function show(Request $request, string $tag): CrmTagResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.view'), 403);

        return new CrmTagResource($this->tag($request, $tag));
    }

    public function update(StoreCrmTagRequest $request, string $tag): CrmTagResource
    {
        $model = $this->tag($request, $tag);
        $old = $model->toArray();
        $model->update(['name' => trim($request->validated('name')), 'normalized_name' => mb_strtolower(trim($request->validated('name'))), 'color' => $request->validated('color')]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'update', 'crm_tag', $model, $old, $model->fresh()->toArray());

        return new CrmTagResource($model->fresh());
    }

    public function destroy(Request $request, string $tag): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.accounts.manage'), 403);
        $model = $this->tag($request, $tag);
        $old = $model->toArray();
        $this->audit->record($request, $request->user(), $this->companyId($request), 'delete', 'crm_tag', $model, $old, null);
        $model->delete();

        return response()->noContent();
    }

    public function attach(AttachCrmTagsRequest $request): AnonymousResourceCollection
    {
        $entity = $this->entity($request->validated('entity_type'), $request->validated('entity_id'), $this->companyId($request));
        $old = $entity->tags()->pluck('crm_tags.id')->all();
        $entity->tags()->syncWithPivotValues($request->validated('tag_ids'), ['company_id' => $this->companyId($request)]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'tag', 'crm', $entity, ['tag_ids' => $old], ['tag_ids' => $request->validated('tag_ids')]);

        return CrmTagResource::collection($entity->tags()->orderBy('name')->get());
    }

    private function entity(string $type, string $id, string $companyId): Model
    {
        $class = match ($type) {
            'account' => CrmAccount::class, 'contact' => CrmContact::class, 'lead' => CrmLead::class, 'deal' => CrmDeal::class
        };

        return $class::query()->where('company_id', $companyId)->findOrFail($id);
    }

    private function tag(Request $request, string $id): CrmTag
    {
        return CrmTag::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
