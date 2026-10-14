<?php

namespace App\Http\Controllers\Api\V1\Fbr;

use App\Http\Controllers\Controller;
use App\Http\Resources\FbrReferenceValueResource;
use App\Models\FbrReferenceValue;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class FbrReferenceDataController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'fbr.configuration.view');
        $data = $request->validate([
            'category' => ['nullable', Rule::in(['PROVINCE', 'DOCUMENT_TYPE', 'HS_CODE', 'UOM', 'SALE_TYPE', 'RATE', 'SRO_SCHEDULE', 'SRO_ITEM', 'SCENARIO'])],
            'include_inactive' => ['nullable', 'boolean'],
            'parent_code' => ['nullable', 'string', 'max:120'],
            'source_version' => ['nullable', 'string', 'max:100'],
            'effective_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = FbrReferenceValue::query();
        if (isset($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (! ($data['include_inactive'] ?? false)) {
            $query->where('is_active', true);
        }
        foreach (['parent_code', 'source_version'] as $filter) {
            if (isset($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        if (isset($data['effective_on'])) {
            $query->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $data['effective_on']))
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $data['effective_on']));
        }

        return FbrReferenceValueResource::collection($query->orderBy('category')->orderBy('code')->get());
    }

    public function search(Request $request): AnonymousResourceCollection
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'fbr.configuration.view');
        $data = $request->validate([
            'category' => ['required', Rule::in(['PROVINCE', 'DOCUMENT_TYPE', 'HS_CODE', 'UOM', 'SALE_TYPE', 'RATE', 'SRO_SCHEDULE', 'SRO_ITEM'])],
            'search' => ['required', 'string', 'min:2', 'max:120'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'], 'source' => ['nullable', 'string', 'max:60'],
        ]);
        $term = '%'.trim($data['search']).'%';
        $query = FbrReferenceValue::query()->where('category', $data['category'])->where('is_active', true)
            ->where(fn (Builder $builder): Builder => $builder->where('code', 'like', $term)->orWhere('label', 'like', $term));
        if (isset($data['source'])) {
            $query->where('source', $data['source']);
        }

        return FbrReferenceValueResource::collection($query->orderBy('code')->orderBy('id')->paginate($data['per_page'] ?? 25));
    }
}
