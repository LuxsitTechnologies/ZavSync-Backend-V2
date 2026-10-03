<?php

namespace Tests\Feature\Stage16;

use App\Models\Company;
use App\Models\CompanyAnnouncement;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_attachment_publish_and_employee_feed_are_private_and_idempotent(): void
    {
        [$employeeUser, $admin, $company] = $this->context();
        Storage::fake('local');
        $payload = ['title' => 'Office announcement', 'description' => 'Please review the new schedule.', 'priority' => 'HIGH'];
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/hrm/announcements', $payload, $this->headers($company, 'announcement-create-1'))
            ->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('version', 1);
        $id = $created->json('id');
        $this->postJson('/api/v1/hrm/announcements', $payload, $this->headers($company, 'announcement-create-1'))
            ->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/api/v1/hrm/announcements', [...$payload, 'title' => 'Changed'], $this->headers($company, 'announcement-create-1'))
            ->assertConflict()->assertJsonPath('error_code', 'ANNOUNCEMENT_IDEMPOTENCY_CONFLICT');
        $attached = $this->postJson("/api/v1/hrm/announcements/{$id}/attachment", [
            'file' => UploadedFile::fake()->createWithContent('notice.pdf', '%PDF-1.4 private announcement'), 'version' => 1,
        ], $this->headers($company, 'announcement-file-1'))->assertOk()->assertJsonPath('version', 2);
        $this->assertSame('notice.pdf', $attached->json('attachment.original_filename'));
        $this->postJson("/api/v1/hrm/announcements/{$id}/attachment", [
            'file' => UploadedFile::fake()->createWithContent('notice.pdf', '%PDF-1.4 private announcement'), 'version' => 1,
        ], $this->headers($company, 'announcement-file-1'))->assertOk()->assertJsonPath('version', 2);
        $this->get("/api/v1/hrm/announcements/{$id}/attachment", $this->headers($company))->assertOk();
        $this->getJson('/api/v1/platform/documents', $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($employeeUser);
        $this->getJson('/api/v1/employee/announcements', $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/employee/announcements/{$id}", $this->headers($company))->assertNotFound();
        $this->get("/api/v1/employee/announcements/{$id}/attachment", $this->headers($company))->assertNotFound();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/hrm/announcements/{$id}/publish", ['version' => 1], $this->headers($company))->assertConflict();
        $this->postJson("/api/v1/hrm/announcements/{$id}/publish", ['version' => 2], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'PUBLISHED')->assertJsonPath('version', 3);
        $this->postJson("/api/v1/hrm/announcements/{$id}/publish", ['version' => 2], $this->headers($company))->assertOk();
        $this->assertDatabaseCount('platform_notifications', 1);
        $this->patchJson("/api/v1/hrm/announcements/{$id}", [...$payload, 'version' => 3], $this->headers($company))->assertConflict();
        $this->postJson("/api/v1/hrm/announcements/{$id}/attachment", [
            'file' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'), 'version' => 3,
        ], $this->headers($company, 'announcement-file-2'))->assertConflict();

        Sanctum::actingAs($employeeUser);
        $this->getJson('/api/v1/employee/announcements', $this->headers($company))->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/employee/announcements/{$id}", $this->headers($company))->assertOk()->assertJsonMissing(['storage_key']);
        $this->get("/api/v1/employee/announcements/{$id}/attachment", $this->headers($company))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_expiry_tenancy_identity_and_permissions_restrict_employee_feed(): void
    {
        [$employeeUser, $admin, $company, $employee] = $this->context();
        $published = CompanyAnnouncement::factory()->create([
            'company_id' => $company->id, 'status' => 'PUBLISHED', 'published_at' => now()->subDay(), 'expires_at' => now()->subHour(),
            'created_by' => $admin->id,
        ]);
        $foreignCompany = Company::factory()->create();
        $foreign = CompanyAnnouncement::factory()->create([
            'company_id' => $foreignCompany->id, 'status' => 'PUBLISHED', 'published_at' => now()->subDay(),
            'created_by' => $admin->id,
        ]);
        Sanctum::actingAs($employeeUser);
        $this->getJson('/api/v1/employee/announcements', $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/employee/announcements/{$published->id}", $this->headers($company))->assertNotFound();
        $this->getJson("/api/v1/employee/announcements/{$foreign->id}", $this->headers($company))->assertNotFound();
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $employeeUser->id)->firstOrFail()
            ->forceFill(['employee_id' => null])->save();
        $this->getJson('/api/v1/employee/announcements', $this->headers($company))->assertConflict();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/hrm/announcements/{$foreign->id}", $this->headers($company))->assertNotFound();
        $this->getJson("/api/v1/hrm/announcements/{$published->id}", $this->headers($company))->assertOk();
        $this->assertSame($company->id, $employee->company_id);
    }

    /** @return array{User, User, Company, Employee} */
    private function context(): array
    {
        [$employeeUser, $company] = $this->actingAsCompanyUser(['employee.announcements.view']);
        $employee = Employee::factory()->for($company)->create(['created_by' => $employeeUser->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $employeeUser->id)->firstOrFail()
            ->forceFill(['employee_id' => $employee->id])->save();
        $admin = User::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Announcement administrator']);
        foreach (['announcements.view', 'announcements.manage', 'announcements.publish', 'platform.documents.view'] as $permissionName) {
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
