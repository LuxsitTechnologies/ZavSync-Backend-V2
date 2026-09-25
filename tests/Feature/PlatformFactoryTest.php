<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CompanyEntitlement;
use App\Models\CompanyExport;
use App\Models\CompanyInvitation;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Document;
use App\Models\NotificationPreference;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\PlatformModule;
use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage10_factories_create_valid_records_independently(): void
    {
        $planModule = PlanModule::factory()->create();
        $models = [
            CompanyUser::factory()->create(), Role::factory()->create(), Permission::factory()->create(), AuditLog::factory()->create(),
            CompanyInvitation::factory()->create(), CompanySetting::factory()->create(), PlatformModule::factory()->create(), Plan::factory()->create(),
            Subscription::factory()->create(), CompanyEntitlement::factory()->create(),
            PlatformNotification::factory()->create(), NotificationPreference::factory()->create(), Document::factory()->create(),
            SecurityEvent::factory()->create(), CompanyExport::factory()->create(),
        ];

        foreach ($models as $model) {
            $this->assertModelExists($model);
        }
        $this->assertDatabaseHas('plan_modules', ['plan_id' => $planModule->plan_id, 'module_key' => $planModule->module_key]);
    }
}
