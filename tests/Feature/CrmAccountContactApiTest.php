<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CrmAccount;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmAccountContactApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_crud_search_filter_pagination_and_archive_are_persisted_and_audited(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $created = $this->postJson('/api/v1/crm/accounts', $this->accountPayload(['name' => 'North Star Systems']), $headers)
            ->assertSuccessful()->assertJsonPath('name', 'North Star Systems')->json();

        $this->getJson('/api/v1/crm/accounts?search=North&status=PROSPECT&per_page=1', $headers)
            ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1);
        $this->putJson('/api/v1/crm/accounts/'.$created['id'], $this->accountPayload(['name' => 'North Star Holdings', 'status' => 'ACTIVE']), $headers)
            ->assertSuccessful()->assertJsonPath('status', 'ACTIVE');
        $this->deleteJson('/api/v1/crm/accounts/'.$created['id'], [], $headers)->assertNoContent();

        $this->assertDatabaseHas('crm_accounts', ['id' => $created['id'], 'company_id' => $context['company']->id, 'is_archived' => true, 'status' => 'INACTIVE']);
        $this->assertSame(3, AuditLog::query()->where('entity_id', $created['id'])->count());
    }

    public function test_contacts_can_be_created_updated_searched_and_only_one_is_primary(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $first = $this->postJson('/api/v1/crm/contacts', $this->contactPayload($context['account']->id, ['email' => 'first@example.com', 'is_primary' => true]), $headers)->assertSuccessful()->json();
        $second = $this->postJson('/api/v1/crm/contacts', $this->contactPayload($context['account']->id, ['email' => 'second@example.com', 'first_name' => 'Second', 'is_primary' => true]), $headers)->assertSuccessful()->json();

        $this->assertDatabaseHas('crm_contacts', ['id' => $first['id'], 'is_primary' => false]);
        $this->assertDatabaseHas('crm_contacts', ['id' => $second['id'], 'is_primary' => true]);
        $this->getJson('/api/v1/crm/contacts?search=second@example.com', $headers)->assertSuccessful()->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/crm/contacts/'.$second['id'], $this->contactPayload($context['account']->id, ['first_name' => 'Updated', 'email' => 'second@example.com']), $headers)->assertSuccessful()->assertJsonPath('name', 'Updated Contact');
    }

    public function test_cross_company_relationships_and_records_are_rejected(): void
    {
        $context = $this->stage9CrmContext();
        $foreignCompany = Company::factory()->create();
        $foreignAccount = CrmAccount::factory()->for($foreignCompany)->create();
        $headers = $this->headers($context['company']->id);

        $this->postJson('/api/v1/crm/contacts', $this->contactPayload($foreignAccount->id), $headers)->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->getJson('/api/v1/crm/accounts/'.$foreignAccount->id, $headers)->assertNotFound();
        $this->putJson('/api/v1/crm/accounts/'.$foreignAccount->id, $this->accountPayload(), $headers)->assertNotFound();
        $this->deleteJson('/api/v1/crm/accounts/'.$foreignAccount->id, [], $headers)->assertNotFound();
    }

    public function test_account_customer_handoff_creates_reuses_and_traces_stage_three_customer(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $payload = $this->customerPayload(['idempotency_key' => 'handoff-account-1']);
        $first = $this->postJson('/api/v1/crm/accounts/'.$context['account']->id.'/customer-handoff', $payload, $headers)->assertSuccessful()->json();
        $second = $this->postJson('/api/v1/crm/accounts/'.$context['account']->id.'/customer-handoff', $payload, $headers)->assertSuccessful()->json();

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, Customer::query()->where('company_id', $context['company']->id)->where('ntn', '1234567')->count());
        $this->assertDatabaseHas('crm_accounts', ['id' => $context['account']->id, 'customer_id' => $first['id'], 'status' => 'CUSTOMER']);
    }

    public function test_account_can_link_an_existing_customer_and_rejects_invalid_tax_identity(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $customer = Customer::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $this->postJson('/api/v1/crm/accounts/'.$context['account']->id.'/customer-handoff', ['idempotency_key' => 'link-existing', 'customer_id' => $customer->id], $headers)
            ->assertSuccessful()->assertJsonPath('id', $customer->id);

        $other = CrmAccount::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $this->postJson('/api/v1/crm/accounts/'.$other->id.'/customer-handoff', $this->customerPayload(['idempotency_key' => 'invalid-tax', 'ntn' => '12']), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('ntn');
    }

    /** @return array<string, mixed> */
    private function accountPayload(array $overrides = []): array
    {
        return [...['name' => 'Acme Private Limited', 'legal_name' => 'Acme Private Limited', 'email' => 'hello@acme.example', 'phone' => '+923001234567', 'website' => 'https://acme.example', 'ntn' => '1234567', 'registration_number' => 'REG-100', 'industry' => 'Technology', 'account_type' => 'BUSINESS', 'address' => 'Main Boulevard', 'city' => 'Lahore', 'country' => 'PK', 'postal_code' => '54000', 'source' => 'Website', 'status' => 'PROSPECT', 'notes' => 'Priority account'], ...$overrides];
    }

    /** @return array<string, mixed> */
    private function contactPayload(string $accountId, array $overrides = []): array
    {
        return [...['account_id' => $accountId, 'first_name' => 'Primary', 'last_name' => 'Contact', 'job_title' => 'CFO', 'department' => 'Finance', 'email' => 'primary@example.com', 'phone' => '+923001234567', 'mobile' => '+923009876543', 'is_primary' => false, 'address' => 'Lahore', 'notes' => null, 'status' => 'ACTIVE'], ...$overrides];
    }

    /** @return array<string, mixed> */
    private function customerPayload(array $overrides = []): array
    {
        return [...['idempotency_key' => 'handoff-default', 'name' => 'Acme Customer', 'legal_name' => 'Acme Customer Limited', 'type' => 'business', 'ntn' => '1234567', 'email' => 'billing@acme.example', 'phone' => '+923001234567', 'billing_address' => 'Main Boulevard', 'city' => 'Lahore', 'country' => 'PK', 'postal_code' => '54000', 'contact_person' => 'Primary Contact', 'payment_terms_days' => 30, 'credit_limit' => 1_000_000, 'currency' => 'PKR', 'notes' => null], ...$overrides];
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
