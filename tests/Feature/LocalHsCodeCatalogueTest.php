<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalHsCodeCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_authorized_platform_admin_manages_local_hs_values_and_company_lookup_labels_source(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['fbr.hs.manage', 'fbr.configuration.view']);
        $headers = ['X-Company-Id' => $company->id];
        $path = '/api/v1/platform/fbr/hs-codes';
        $this->postJson($path, ['code' => '1234.5678', 'description' => 'A locally managed product'], $headers)->assertForbidden();
        $user->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($user);

        $id = $this->postJson($path, ['code' => '1234.5678', 'description' => 'A locally managed product'], $headers)
            ->assertCreated()->assertJsonPath('source', 'LOCAL_MANAGED')->assertJsonPath('label', 'A locally managed product')->json('id');
        $this->postJson($path, ['code' => '1234.5678', 'description' => 'Duplicate'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson('/api/v1/pakistan-fbr/reference-data/search?category=HS_CODE&search=1234', $headers)
            ->assertOk()->assertJsonPath('data.0.source', 'LOCAL_MANAGED')->assertJsonPath('data.0.code', '1234.5678');
        $this->patchJson("$path/$id", ['description' => 'Revised local product', 'is_active' => false], $headers)
            ->assertOk()->assertJsonPath('is_active', false);
        $this->getJson('/api/v1/pakistan-fbr/reference-data/search?category=HS_CODE&search=1234', $headers)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("$path?include_inactive=1", $headers)->assertOk()->assertJsonPath('data.0.label', 'Revised local product');
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'entity_id' => $id, 'action' => 'local_hs_code_updated']);
    }

    public function test_platform_flag_does_not_bypass_permission_or_company_isolation(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['fbr.configuration.view']);
        $user->forceFill(['is_platform_admin' => true])->save();
        $this->postJson('/api/v1/platform/fbr/hs-codes', ['code' => '1111.2222', 'description' => 'Denied'],
            ['X-Company-Id' => $company->id])->assertForbidden();
        $this->assertDatabaseCount('fbr_reference_values', 0);
    }
}
