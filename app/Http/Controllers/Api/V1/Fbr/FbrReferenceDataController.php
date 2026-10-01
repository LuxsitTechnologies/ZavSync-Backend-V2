<?php

namespace App\Http\Controllers\Api\V1\Fbr;

use App\Http\Controllers\Controller;
use App\Http\Resources\FbrReferenceValueResource;
use App\Models\FbrReferenceValue;
use App\Services\Platform\PlatformAccessService;
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
}
