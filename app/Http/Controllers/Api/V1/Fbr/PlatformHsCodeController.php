<?php

namespace App\Http\Controllers\Api\V1\Fbr;

use App\Http\Controllers\Controller;
use App\Http\Resources\FbrReferenceValueResource;
use App\Models\FbrReferenceValue;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PlatformHsCodeController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->authorize($request);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'], 'include_inactive' => ['nullable', 'boolean']]);
        $query = FbrReferenceValue::query()->where('category', 'HS_CODE')->where('source', 'LOCAL_MANAGED');
        if (! ($data['include_inactive'] ?? false)) {
            $query->where('is_active', true);
        }
        if (filled($data['search'] ?? null)) {
            $term = '%'.trim($data['search']).'%';
            $query->where(fn ($builder) => $builder->where('code', 'like', $term)->orWhere('label', 'like', $term));
        }

        return FbrReferenceValueResource::collection($query->orderBy('code')->orderBy('id')->paginate($data['per_page'] ?? 25));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120', Rule::unique('fbr_reference_values')->where('category', 'HS_CODE')->where('source_version', 'local-managed-v1')],
            'description' => ['required', 'string', 'max:255'],
        ]);
        $value = FbrReferenceValue::query()->create([
            'category' => 'HS_CODE', 'code' => $data['code'], 'label' => $data['description'],
            'source' => 'LOCAL_MANAGED', 'source_version' => 'local-managed-v1', 'is_active' => true,
        ]);
        $this->audit->record($request, $request->user(), $companyId, 'local_hs_code_created', 'pakistan_fbr', $value,
            null, ['code' => $value->code, 'description' => $value->label, 'source' => 'LOCAL_MANAGED']);

        return response()->json(new FbrReferenceValueResource($value), 201);
    }

    public function update(Request $request, string $hsCode): FbrReferenceValueResource
    {
        $companyId = $this->authorize($request);
        $value = FbrReferenceValue::query()->where('category', 'HS_CODE')->where('source', 'LOCAL_MANAGED')->findOrFail($hsCode);
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:120', Rule::unique('fbr_reference_values')->where('category', 'HS_CODE')->where('source_version', 'local-managed-v1')->ignore($value->id)],
            'description' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = $value->only(['code', 'label', 'is_active']);
        $value->update([
            'code' => $data['code'] ?? $value->code,
            'label' => $data['description'] ?? $value->label,
            'is_active' => $data['is_active'] ?? $value->is_active,
        ]);
        $this->audit->record($request, $request->user(), $companyId, 'local_hs_code_updated', 'pakistan_fbr', $value,
            $before, $value->only(['code', 'label', 'is_active']));

        return new FbrReferenceValueResource($value);
    }

    private function authorize(Request $request): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        abort_unless($request->user()->is_platform_admin, 403, 'Platform administrator access is required.');
        $this->access->authorize($request->user(), $companyId, 'fbr.hs.manage');

        return $companyId;
    }
}
