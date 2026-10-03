<?php

namespace Tests\Feature\Stage16;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeSelfProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_address_is_the_only_self_editable_employee_field_and_replay_does_not_duplicate_audit(): void
    {
        [$user, $company, $employee] = $this->context();
        $this->getJson('/api/v1/employee/me', $this->headers($company))->assertOk()
            ->assertJsonPath('self_editable', true)->assertJsonPath('employee.address', null)->assertJsonPath('employee.self_profile_version', 1);
        $this->patchJson('/api/v1/employee/me', ['address' => 'Office Road, Lahore', 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('employee.address', 'Office Road, Lahore')->assertJsonPath('employee.self_profile_version', 2);
        $this->patchJson('/api/v1/employee/me', ['address' => 'Office Road, Lahore', 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('employee.self_profile_version', 2);
        $this->patchJson('/api/v1/employee/me', ['address' => 'Another Road', 'version' => 1], $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'EMPLOYEE_PROFILE_VERSION_STALE');
        $this->patchJson('/api/v1/employee/me', ['address' => 'Other', 'version' => 2, 'designation' => 'Chief'], $this->headers($company))
            ->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_PROFILE_FIELD_FORBIDDEN');
        $this->assertSame('Office Road, Lahore', $employee->fresh()->address);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'employee_self_address_updated')->count());
        $this->assertNotSame('Office Road, Lahore', DB::table('employees')->where('id', $employee->id)->value('address'));
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('attendance_sessions', 0);
    }

    public function test_emergency_contact_create_update_remove_are_private_encrypted_and_idempotent(): void
    {
        [, $company, $employee] = $this->context();
        $payload = ['name' => 'Sara Contact', 'relationship' => 'Sibling', 'phone' => '+92 301 555 1212'];
        $created = $this->postJson('/api/v1/employee/emergency-contacts', $payload, $this->headers($company, 'emergency-contact-1'))
            ->assertCreated()->assertJsonPath('name', 'Sara Contact')->assertJsonPath('version', 1);
        $id = $created->json('id');
        $this->assertEqualsCanonicalizing(['id', 'name', 'relationship', 'phone', 'version', 'created_at'], array_keys($created->json()));
        $this->postJson('/api/v1/employee/emergency-contacts', $payload, $this->headers($company, 'emergency-contact-1'))
            ->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/api/v1/employee/emergency-contacts', [...$payload, 'phone' => 'Changed'], $this->headers($company, 'emergency-contact-1'))
            ->assertConflict()->assertJsonPath('error_code', 'EMERGENCY_CONTACT_IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('employee_emergency_contacts', 1);
        $this->assertNotSame('Sara Contact', DB::table('employee_emergency_contacts')->where('id', $id)->value('name'));
        $this->getJson('/api/v1/employee/emergency-contacts', $this->headers($company))->assertOk()->assertJsonPath('data.0.id', $id);
        $this->patchJson("/api/v1/employee/emergency-contacts/{$id}", [...$payload, 'phone' => '+92 300 444 1212', 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('version', 2);
        $this->patchJson("/api/v1/employee/emergency-contacts/{$id}", [...$payload, 'phone' => '+92 300 444 1212', 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('version', 2);
        $this->patchJson("/api/v1/employee/emergency-contacts/{$id}", [...$payload, 'phone' => 'New', 'version' => 1], $this->headers($company))
            ->assertConflict();
        $this->deleteJson("/api/v1/employee/emergency-contacts/{$id}", ['version' => 2], $this->headers($company))->assertOk();
        $this->deleteJson("/api/v1/employee/emergency-contacts/{$id}", ['version' => 2], $this->headers($company))->assertOk();
        $this->getJson("/api/v1/employee/emergency-contacts/{$id}", $this->headers($company))->assertNotFound();
        $this->assertSoftDeleted('employee_emergency_contacts', ['id' => $id, 'employee_id' => $employee->id]);
        $this->assertSame(3, DB::table('audit_logs')->where('module', 'employee_profile')->count());
    }

    public function test_former_employee_cannot_edit_but_retains_authorized_history(): void
    {
        [, $company, $employee] = $this->context();
        $contact = EmployeeEmergencyContact::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
        $employee->update(['status' => 'terminated']);
        $this->getJson('/api/v1/employee/me', $this->headers($company))->assertOk()->assertJsonPath('self_editable', false);
        $this->getJson("/api/v1/employee/emergency-contacts/{$contact->id}", $this->headers($company))->assertOk();
        $this->patchJson('/api/v1/employee/me', ['address' => 'Not allowed', 'version' => 1], $this->headers($company))->assertForbidden();
        $this->postJson('/api/v1/employee/emergency-contacts', ['name' => 'New', 'relationship' => 'Parent', 'phone' => '123'], $this->headers($company, 'former-contact-1'))->assertForbidden();
    }

    public function test_link_permission_and_tenant_scope_protect_contact_records(): void
    {
        [$user, $company, $employee] = $this->context();
        $otherCompany = Company::factory()->create();
        $otherEmployee = Employee::factory()->for($otherCompany)->create();
        $foreign = EmployeeEmergencyContact::factory()->create(['company_id' => $otherCompany->id, 'employee_id' => $otherEmployee->id]);
        $this->getJson("/api/v1/employee/emergency-contacts/{$foreign->id}", $this->headers($company))->assertNotFound();
        $this->patchJson("/api/v1/employee/emergency-contacts/{$foreign->id}", ['name' => 'Wrong', 'relationship' => 'Parent', 'phone' => '123', 'version' => 1], $this->headers($company))->assertNotFound();
        $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();
        $membership->forceFill(['employee_id' => null])->save();
        $this->getJson('/api/v1/employee/emergency-contacts', $this->headers($company))->assertConflict();
        $this->patchJson('/api/v1/employee/me', ['address' => 'Not allowed', 'version' => 1], $this->headers($company))->assertConflict();
        $this->assertNull($employee->fresh()->address);
        $this->assertSame($foreign->name, $foreign->fresh()->name);
    }

    /** @return array{User, Company, Employee} */
    private function context(): array
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.self.view', 'employee.profile.edit']);
        $employee = Employee::factory()->for($company)->create(['created_by' => $user->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail()
            ->forceFill(['employee_id' => $employee->id])->save();

        return [$user, $company, $employee];
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
