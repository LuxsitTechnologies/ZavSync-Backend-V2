<?php

namespace Tests\Feature\Stage16;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeIssuedDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_document_is_invisible_until_explicit_release_and_release_is_idempotent(): void
    {
        [$employeeUser, $admin, $company, $employee] = $this->context();
        Storage::fake('local');
        Sanctum::actingAs($admin);
        $payload = ['employee_id' => $employee->id, 'file' => UploadedFile::fake()->createWithContent('contract.pdf', '%PDF-1.4 employment contract')];
        $created = $this->postJson('/api/v1/hrm/employee-documents', $payload, $this->headers($company, 'issued-contract-1'))
            ->assertCreated()->assertJsonPath('released_at', null);
        $id = $created->json('id');
        $this->assertSame($employee->id, $created->json('employee_id'));
        $this->assertNotNull(Document::query()->findOrFail($id)->storage_key);
        $this->getJson('/api/v1/hrm/employee-documents?employee_id='.$employee->id, $this->headers($company))->assertOk()->assertJsonPath('data.0.id', $id);
        Sanctum::actingAs($employeeUser);
        $this->getJson('/api/v1/employee/documents', $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/employee/documents/{$id}", $this->headers($company))->assertNotFound();
        $this->get("/api/v1/employee/documents/{$id}/download", $this->headers($company))->assertNotFound();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/hrm/employee-documents', [
            'employee_id' => $employee->id, 'file' => UploadedFile::fake()->createWithContent('contract.pdf', '%PDF-1.4 employment contract'),
        ], $this->headers($company, 'issued-contract-1'))->assertOk()->assertJsonPath('id', $id);
        $this->postJson("/api/v1/hrm/employee-documents/{$id}/release", [], $this->headers($company))->assertOk();
        $releasedAt = Document::query()->findOrFail($id)->employee_released_at;
        $this->assertNotNull($releasedAt);
        $this->postJson("/api/v1/hrm/employee-documents/{$id}/release", [], $this->headers($company))->assertOk();
        $this->assertEquals($releasedAt, Document::query()->findOrFail($id)->employee_released_at);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('platform_notifications', 1);

        Sanctum::actingAs($employeeUser);
        $this->getJson('/api/v1/employee/documents', $this->headers($company))->assertOk()->assertJsonPath('data.0.category', 'ISSUED');
        $this->getJson("/api/v1/employee/documents/{$id}", $this->headers($company))->assertOk()->assertJsonMissing(['storage_key']);
        $this->get("/api/v1/employee/documents/{$id}/download", $this->headers($company))->assertOk();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_admin_permissions_generic_document_bypass_and_cross_company_access_are_rejected(): void
    {
        [$employeeUser, $admin, $company, $employee] = $this->context();
        Storage::fake('local');
        Sanctum::actingAs($admin);
        $id = $this->postJson('/api/v1/hrm/employee-documents', [
            'employee_id' => $employee->id, 'file' => UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf'),
        ], $this->headers($company, 'issued-contract-2'))->assertCreated()->json('id');
        $this->getJson('/api/v1/platform/documents', $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/platform/documents/{$id}/download", $this->headers($company))->assertNotFound();
        $this->deleteJson("/api/v1/platform/documents/{$id}", [], $this->headers($company))->assertNotFound();
        $this->postJson('/api/v1/platform/documents', [
            'documentable_type' => 'employee', 'documentable_id' => $employee->id,
            'category' => 'employee_issued', 'file' => UploadedFile::fake()->create('other.pdf', 10, 'application/pdf'),
        ], $this->headers($company))->assertNotFound();

        $foreignCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->for($foreignCompany)->create();
        $this->postJson('/api/v1/hrm/employee-documents', [
            'employee_id' => $foreignEmployee->id, 'file' => UploadedFile::fake()->create('other.pdf', 10, 'application/pdf'),
        ], $this->headers($company, 'issued-contract-3'))->assertNotFound();
        $this->getJson('/api/v1/hrm/employee-documents?employee_id='.$foreignEmployee->id, $this->headers($company))->assertNotFound();
        Sanctum::actingAs($employeeUser);
        $this->getJson("/api/v1/hrm/employee-documents/{$id}", $this->headers($company))->assertForbidden();
        $this->postJson("/api/v1/hrm/employee-documents/{$id}/release", [], $this->headers($company))->assertForbidden();
    }

    /** @return array{User, User, Company, Employee} */
    private function context(): array
    {
        [$employeeUser, $company] = $this->actingAsCompanyUser(['employee.documents.view']);
        $employee = Employee::factory()->for($company)->create(['created_by' => $employeeUser->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $employeeUser->id)->firstOrFail()
            ->forceFill(['employee_id' => $employee->id])->save();
        $admin = User::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Employee document administrator']);
        foreach (['employee.documents.admin.view', 'employee.documents.issue', 'employee.documents.release', 'platform.documents.view', 'platform.documents.manage'] as $permissionName) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permissionName]));
        }
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'is_active' => true]);

        return [$employeeUser, $admin, $company, $employee];
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
