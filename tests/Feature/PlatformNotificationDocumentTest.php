<?php

namespace Tests\Feature;

use App\Jobs\DeliverPlatformNotification;
use App\Models\Customer;
use App\Models\Document;
use App\Models\NotificationPreference;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\Platform\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformNotificationDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_service_creates_in_app_and_queued_email_delivery(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [$user, $company] = $this->actingAsCompanyUser([]);

        $notification = app(NotificationService::class)->create($company->id, $user->id, 'invoice.updated', 'Invoice updated', 'Invoice INV-1 changed.');

        $this->assertModelExists($notification);
        $this->assertDatabaseHas('platform_notifications', ['company_id' => $company->id, 'recipient_id' => $user->id, 'channel' => 'EMAIL']);
        Queue::assertPushed(DeliverPlatformNotification::class, 2);
    }

    public function test_noncritical_disabled_preference_suppresses_notification(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [$user, $company] = $this->actingAsCompanyUser([]);
        NotificationPreference::factory()->for($company)->for($user)->create(['type' => 'invoice.updated', 'in_app_enabled' => false, 'email_enabled' => false]);

        app(NotificationService::class)->create($company->id, $user->id, 'invoice.updated', 'Hidden', 'Hidden');

        $this->assertDatabaseCount('platform_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_notification_delivery_job_is_idempotent_on_retry(): void
    {
        [$user, $company] = $this->actingAsCompanyUser([]);
        $notification = PlatformNotification::factory()->for($company)->create(['recipient_id' => $user->id, 'channel' => 'IN_APP', 'delivery_state' => 'PENDING', 'delivered_at' => null]);
        $job = new DeliverPlatformNotification($notification->id);

        $job->handle();
        $deliveredAt = $notification->fresh()->delivered_at;
        $job->handle();

        $this->assertNotNull($deliveredAt);
        $this->assertTrue($notification->fresh()->delivered_at->equalTo($deliveredAt));
    }

    public function test_critical_notification_preferences_cannot_be_disabled(): void
    {
        [, $company] = $this->actingAsCompanyUser([]);

        $this->putJson('/api/v1/platform/notification-preferences', ['preferences' => [[
            'type' => 'security.login', 'in_app_enabled' => false, 'email_enabled' => true,
        ]]], ['X-Company-Id' => $company->id])->assertUnprocessable()->assertJsonPath('error_code', 'CRITICAL_NOTIFICATION_REQUIRED');
    }

    public function test_notification_inbox_is_recipient_and_company_scoped(): void
    {
        [$user, $company] = $this->actingAsCompanyUser([]);
        $mine = PlatformNotification::factory()->for($company)->create(['recipient_id' => $user->id]);
        PlatformNotification::factory()->for($company)->for(User::factory(), 'recipient')->create();
        PlatformNotification::factory()->create(['recipient_id' => $user->id]);

        $this->getJson('/api/v1/platform/notifications', ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonCount(1, 'notifications.data')->assertJsonPath('notifications.data.0.id', $mine->id);
        $this->postJson("/api/v1/platform/notifications/{$mine->id}/read", [], ['X-Company-Id' => $company->id])->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);
    }

    public function test_allowed_document_upload_and_authorized_download_use_private_storage(): void
    {
        Storage::fake('local');
        [$user, $company] = $this->actingAsCompanyUser(['platform.documents.view', 'platform.documents.manage']);
        $customer = Customer::factory()->for($company)->create(['created_by' => $user->id]);
        $response = $this->postJson('/api/v1/platform/documents', [
            'documentable_type' => 'customer', 'documentable_id' => $customer->id, 'category' => 'contract',
            'file' => UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf'),
        ], ['X-Company-Id' => $company->id])->assertCreated();
        $document = Document::query()->findOrFail($response->json('id'));
        Storage::disk('local')->assertExists($document->storage_key);

        $this->get("/api/v1/platform/documents/{$document->id}/download", ['X-Company-Id' => $company->id])->assertOk();
    }

    public function test_invalid_extension_mime_and_oversized_uploads_are_rejected(): void
    {
        Storage::fake('local');
        [$user, $company] = $this->actingAsCompanyUser(['platform.documents.manage']);
        $customer = Customer::factory()->for($company)->create(['created_by' => $user->id]);
        foreach ([
            UploadedFile::fake()->create('payload.php', 10, 'application/x-httpd-php'),
            UploadedFile::fake()->create('oversized.pdf', 10_241, 'application/pdf'),
        ] as $file) {
            $this->postJson('/api/v1/platform/documents', ['documentable_type' => 'customer', 'documentable_id' => $customer->id, 'file' => $file], ['X-Company-Id' => $company->id])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_document_related_entity_and_download_are_tenant_protected(): void
    {
        Storage::fake('local');
        [$user, $company] = $this->actingAsCompanyUser(['platform.documents.view', 'platform.documents.manage']);
        $foreignCustomer = Customer::factory()->create(['created_by' => $user->id]);

        $this->postJson('/api/v1/platform/documents', [
            'documentable_type' => 'customer', 'documentable_id' => $foreignCustomer->id,
            'file' => UploadedFile::fake()->create('agreement.pdf', 10, 'application/pdf'),
        ], ['X-Company-Id' => $company->id])->assertNotFound();

        $foreign = Document::factory()->create();
        $this->getJson("/api/v1/platform/documents/{$foreign->id}/download", ['X-Company-Id' => $company->id])->assertNotFound();
    }

    public function test_document_deletion_requires_manage_permission_and_is_audited(): void
    {
        Storage::fake('local');
        [, $company] = $this->actingAsCompanyUser(['platform.documents.view']);
        $document = Document::factory()->for($company)->create();
        Storage::disk('local')->put($document->storage_key, 'content');

        $this->deleteJson("/api/v1/platform/documents/{$document->id}", [], ['X-Company-Id' => $company->id])->assertForbidden();

        [$manager, $managedCompany] = $this->actingAsCompanyUser(['platform.documents.manage']);
        $managed = Document::factory()->for($managedCompany)->create(['uploaded_by' => $manager->id]);
        Storage::disk('local')->put($managed->storage_key, 'content');
        $this->deleteJson("/api/v1/platform/documents/{$managed->id}", [], ['X-Company-Id' => $managedCompany->id])->assertOk();
        Storage::disk('local')->assertMissing($managed->storage_key);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $managedCompany->id, 'action' => 'document_deleted']);
    }

    public function test_company_logo_upload_uses_private_document_foundation(): void
    {
        Storage::fake('local');
        [, $company] = $this->actingAsCompanyUser(['platform.settings.manage']);

        $response = $this->postJson('/api/v1/platform/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)], ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonPath('document.category', 'logo');
        $this->assertDatabaseHas('company_settings', ['company_id' => $company->id, 'logo_path' => $response->json('document.id')]);
        $this->assertDatabaseHas('documents', ['id' => $response->json('document.id'), 'company_id' => $company->id]);
    }
}
