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
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_upload_is_private_listed_and_idempotent(): void
    {
        [$user, $company, $employee] = $this->context();
        Storage::fake('local');
        $headers = $this->headers($company, 'personal-file-one');
        $created = $this->postJson('/api/v1/employee/documents', [
            'file' => UploadedFile::fake()->createWithContent('identity.pdf', '%PDF-1.4 personal document'),
        ], $headers)->assertCreated()->assertJsonPath('category', 'PERSONAL');
        $id = $created->json('id');
        $this->assertEqualsCanonicalizing(
            ['id', 'category', 'original_filename', 'mime_type', 'size_bytes', 'checksum_sha256', 'created_at'],
            array_keys($created->json()),
        );
        $document = Document::query()->findOrFail($id);
        $this->assertSame($company->id, $document->company_id);
        $this->assertSame($employee->id, $document->documentable_id);
        $this->assertSame($user->id, $document->uploaded_by);
        Storage::disk('local')->assertExists($document->storage_key);
        $this->getJson('/api/v1/employee/documents', $this->headers($company))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/employee/documents/{$id}", $this->headers($company))->assertOk()->assertJsonPath('id', $id);
        $this->get("/api/v1/employee/documents/{$id}/download", $this->headers($company))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->postJson('/api/v1/employee/documents', [
            'file' => UploadedFile::fake()->createWithContent('identity.pdf', '%PDF-1.4 personal document'),
        ], $headers)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('documents', 1);
        $this->postJson('/api/v1/employee/documents', [
            'file' => UploadedFile::fake()->createWithContent('identity.pdf', '%PDF-1.4 other document'),
        ], $headers)->assertConflict()->assertJsonPath('error_code', 'EMPLOYEE_DOCUMENT_IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_employee_documents_cannot_be_reached_through_generic_company_document_apis(): void
    {
        [, $company, $employee] = $this->context(['employee.documents.view', 'employee.documents.upload', 'platform.documents.view', 'platform.documents.manage']);
        Storage::fake('local');
        $id = $this->postJson('/api/v1/employee/documents', [
            'file' => UploadedFile::fake()->createWithContent('identity.pdf', '%PDF-1.4 personal document'),
        ], $this->headers($company, 'personal-file-two'))->assertCreated()->json('id');
        $this->getJson('/api/v1/platform/documents', $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/platform/documents/{$id}/download", $this->headers($company))->assertNotFound();
        $this->deleteJson("/api/v1/platform/documents/{$id}", [], $this->headers($company))->assertNotFound();
        $this->postJson('/api/v1/platform/documents', [
            'documentable_type' => 'employee', 'documentable_id' => $employee->id,
            'category' => 'employee_personal', 'file' => UploadedFile::fake()->create('other.pdf', 10, 'application/pdf'),
        ], $this->headers($company))->assertNotFound();
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_identity_tenancy_and_former_employee_boundaries(): void
    {
        [$user, $company, $employee] = $this->context();
        Storage::fake('local');
        $id = $this->postJson('/api/v1/employee/documents', [
            'file' => UploadedFile::fake()->createWithContent('identity.pdf', '%PDF-1.4 personal document'),
        ], $this->headers($company, 'personal-file-three'))->assertCreated()->json('id');

        $otherUser = User::factory()->create();
        $otherEmployee = Employee::factory()->for($company)->create();
        $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();
        $membership->forceFill(['employee_id' => $otherEmployee->id])->save();
        $this->getJson("/api/v1/employee/documents/{$id}", $this->headers($company))->assertNotFound();
        $membership->forceFill(['employee_id' => $employee->id])->save();
        $foreignCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->for($foreignCompany)->create();
        $foreignDocument = Document::factory()->for($foreignCompany)->create([
            'uploaded_by' => $otherUser->id, 'documentable_type' => $foreignEmployee->getMorphClass(),
            'documentable_id' => $foreignEmployee->id, 'category' => 'employee_personal',
        ]);
        $this->getJson("/api/v1/employee/documents/{$foreignDocument->id}", $this->headers($company))->assertNotFound();

        $employee->update(['status' => 'terminated']);
        $this->getJson("/api/v1/employee/documents/{$id}", $this->headers($company))->assertOk();
        $this->postJson('/api/v1/employee/documents', [
            'file' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'),
        ], $this->headers($company, 'personal-file-four'))->assertForbidden();
        $membership->forceFill(['employee_id' => null])->save();
        $this->getJson('/api/v1/employee/documents', $this->headers($company))->assertConflict();
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_view_and_upload_permissions_are_independent_and_unlinked_platform_admin_cannot_bypass_identity(): void
    {
        [$user, $company, $employee] = $this->context(['employee.documents.view']);
        Storage::fake('local');
        $this->getJson('/api/v1/employee/documents', $this->headers($company))->assertOk();
        $this->postJson('/api/v1/employee/documents', [
            'file' => UploadedFile::fake()->create('identity.pdf', 10, 'application/pdf'),
        ], $this->headers($company, 'personal-file-five'))->assertForbidden();

        $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();
        $uploadRole = Role::query()->create(['company_id' => $company->id, 'name' => 'Document uploader']);
        $uploadRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'employee.documents.upload']));
        $membership->forceFill(['role_id' => $uploadRole->id])->save();
        $this->getJson('/api/v1/employee/documents', $this->headers($company))->assertForbidden();
        $this->postJson('/api/v1/employee/documents', [
            'file' => UploadedFile::fake()->create('identity.pdf', 10, 'application/pdf'),
        ], $this->headers($company, 'personal-file-five'))->assertCreated();

        $user->forceFill(['is_platform_admin' => true])->save();
        $membership->forceFill(['employee_id' => null])->save();
        $this->postJson('/api/v1/employee/documents', [
            'file' => UploadedFile::fake()->create('identity.pdf', 10, 'application/pdf'),
        ], $this->headers($company, 'personal-file-six'))->assertConflict();
        $this->assertDatabaseCount('documents', 1);
    }

    /** @param list<string> $permissions @return array{User, Company, Employee} */
    private function context(array $permissions = ['employee.documents.view', 'employee.documents.upload']): array
    {
        [$user, $company] = $this->actingAsCompanyUser($permissions);
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
