<?php

namespace Tests\Feature\Stage15;

use App\Models\CompanyEntitlement;
use App\Models\FbrCompanyConfiguration;
use App\Models\FbrReferenceValue;
use App\Models\PlatformModule;
use App\Services\Platform\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FbrConfigurationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_environment_clears_previous_environment_credential_and_requires_reverification(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['fbr.configuration.manage']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH']);
        $configuration = FbrCompanyConfiguration::factory()->for($company)->create(['environment' => 'SANDBOX', 'updated_by' => $user->id]);
        $this->putJson('/api/v1/pakistan-fbr/configuration', [
            'seller_tax_identifier' => '1234567', 'seller_business_name' => 'Seller', 'seller_province' => 'SINDH',
            'seller_address' => 'Address', 'environment' => 'PRODUCTION',
        ], ['X-Company-Id' => $company->id])->assertOk()->assertJsonPath('credential_configured', false)->assertJsonPath('connection_state', 'NOT_VERIFIED');
        $this->assertNull($configuration->fresh()->credential);
    }

    public function test_authorized_update_encrypts_credential_and_never_serializes_or_audits_it(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['fbr.configuration.view', 'fbr.configuration.manage']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH']);
        $credential = 'fixture-credential-value';

        $response = $this->putJson('/api/v1/pakistan-fbr/configuration', [
            'seller_tax_identifier' => '1234567', 'seller_business_name' => 'Seller Limited', 'seller_province' => 'SINDH',
            'seller_address' => 'Karachi, Pakistan', 'environment' => 'SANDBOX', 'credential' => $credential,
        ], ['X-Company-Id' => $company->id]);

        $response->assertOk()->assertJsonPath('credential_configured', true)->assertJsonMissing(['credential' => $credential]);
        $configuration = FbrCompanyConfiguration::query()->where('company_id', $company->id)->sole();
        $this->assertSame($credential, $configuration->credential);
        $this->assertNotSame($credential, DB::table('fbr_company_configurations')->where('id', $configuration->id)->value('credential'));
        $this->assertStringNotContainsString($credential, (string) DB::table('audit_logs')->where('entity_id', $configuration->id)->value('new_values'));
        $this->assertSame($user->id, $configuration->updated_by);
    }

    public function test_configuration_requires_manage_permission_and_is_tenant_isolated(): void
    {
        [, $company] = $this->actingAsCompanyUser(['fbr.configuration.view']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH']);
        $payload = ['seller_tax_identifier' => '1234567', 'seller_business_name' => 'Seller', 'seller_province' => 'SINDH', 'seller_address' => 'Address', 'environment' => 'SANDBOX'];

        $this->putJson('/api/v1/pakistan-fbr/configuration', $payload, ['X-Company-Id' => $company->id])->assertForbidden();

        $other = $this->actingAsCompanyUser(['fbr.configuration.view']);
        FbrCompanyConfiguration::factory()->for($company)->create(['updated_by' => $other[0]->id]);
        Sanctum::actingAs($other[0]);
        $this->getJson('/api/v1/pakistan-fbr/configuration', ['X-Company-Id' => $company->id])->assertForbidden();
    }

    public function test_reference_data_is_versioned_filterable_and_permission_protected(): void
    {
        [, $company] = $this->actingAsCompanyUser(['fbr.configuration.view']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH', 'source_version' => '2026-01']);
        FbrReferenceValue::factory()->create(['category' => 'UOM', 'code' => 'UNIT', 'source_version' => '2026-01']);

        $this->getJson('/api/v1/pakistan-fbr/reference-data?category=PROVINCE', ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.code', 'SINDH')->assertJsonPath('0.source_version', '2026-01');

        $restricted = $this->actingAsCompanyUser([]);
        $this->getJson('/api/v1/pakistan-fbr/reference-data', ['X-Company-Id' => $restricted[1]->id])->assertForbidden();
    }

    public function test_fbr_configuration_requires_invoicing_entitlement(): void
    {
        [, $company] = $this->actingAsCompanyUser(['fbr.configuration.view']);
        PlatformModule::factory()->create(['key' => 'invoicing']);
        CompanyEntitlement::factory()->for($company)->create(['module_key' => 'invoicing', 'is_enabled' => false]);
        app(EntitlementService::class)->forget($company->id);

        $this->getJson('/api/v1/pakistan-fbr/configuration', ['X-Company-Id' => $company->id])->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
    }
}
