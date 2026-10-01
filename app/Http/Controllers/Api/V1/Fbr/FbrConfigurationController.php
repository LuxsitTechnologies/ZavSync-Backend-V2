<?php

namespace App\Http\Controllers\Api\V1\Fbr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Fbr\UpdateFbrConfigurationRequest;
use App\Http\Resources\FbrCompanyConfigurationResource;
use App\Models\Company;
use App\Models\FbrCompanyConfiguration;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FbrConfigurationController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function show(Request $request): FbrCompanyConfigurationResource
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'fbr.configuration.view');

        return new FbrCompanyConfigurationResource(FbrCompanyConfiguration::query()->where('company_id', $companyId)->firstOrFail());
    }

    public function update(UpdateFbrConfigurationRequest $request): FbrCompanyConfigurationResource
    {
        $companyId = $this->companyId($request);

        return DB::transaction(function () use ($request, $companyId): FbrCompanyConfigurationResource {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $data = $request->validated();
            $configuration = FbrCompanyConfiguration::query()->where('company_id', $companyId)->first();
            $old = $configuration?->only(['seller_tax_identifier', 'seller_business_name', 'seller_province', 'seller_address', 'environment', 'connection_state']);
            if (blank($data['credential'] ?? null)) {
                unset($data['credential']);
                if ($configuration !== null && $configuration->environment !== $data['environment']) {
                    $data['credential'] = null;
                }
            }
            $configuration = FbrCompanyConfiguration::query()->updateOrCreate(
                ['company_id' => $companyId],
                [...$data, 'connection_state' => 'NOT_VERIFIED', 'last_verified_at' => null, 'last_error' => null, 'updated_by' => $request->user()->id],
            );
            $safe = $configuration->only(['seller_tax_identifier', 'seller_business_name', 'seller_province', 'seller_address', 'environment', 'connection_state']);
            $this->audit->record($request, $request->user(), $companyId, 'fbr_configuration_updated', 'fbr', $configuration, $old, $safe);
            $configuration->wasRecentlyCreated = false;

            return new FbrCompanyConfigurationResource($configuration);
        });
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
