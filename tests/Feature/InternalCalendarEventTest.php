<?php

namespace Tests\Feature;

use App\Models\CompanyEntitlement;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InternalCalendarEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_create_edit_and_cancel_without_a_provider_or_accounting_effects(): void
    {
        $context = $this->stage13IntelligenceContext();
        $path = '/api/v1/ai/intelligence/calendar/internal-events';
        $headers = ['X-Company-Id' => $context['company']->id];
        $created = $this->postJson($path, [
            'title' => 'Private review', 'description' => 'Draft agenda',
            'starts_at' => '2026-10-14T10:00:00+05:00', 'ends_at' => '2026-10-14T11:00:00+05:00',
        ], $headers)->assertCreated()->assertJsonPath('version', 1)->assertJsonPath('timezone', 'Asia/Karachi');
        $id = $created->json('id');
        $this->getJson($path, $headers)->assertOk()->assertJsonPath('total', 1);
        $this->getJson($path.'?from=2026-10-14T04:00:00Z&to=2026-10-14T07:00:00Z', $headers)
            ->assertOk()->assertJsonPath('total', 1);
        $this->getJson($path.'?from=2026-10-15T00:00:00Z', $headers)->assertOk()->assertJsonPath('total', 0);
        $this->patchJson("{$path}/{$id}", ['title' => 'Revised', 'version' => 1], $headers)
            ->assertOk()->assertJsonPath('title', 'Revised')->assertJsonPath('version', 2);
        $this->patchJson("{$path}/{$id}", ['title' => 'Stale', 'version' => 1], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'CALENDAR_EVENT_VERSION_STALE');
        $this->postJson("{$path}/{$id}/cancel", ['version' => 2], $headers)
            ->assertOk()->assertJsonPath('status', 'CANCELLED')->assertJsonPath('version', 3);
        $this->postJson("{$path}/{$id}/cancel", ['version' => 2], $headers)->assertOk()->assertJsonPath('version', 3);
        $this->patchJson("{$path}/{$id}", ['title' => 'After cancel', 'version' => 3], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'CALENDAR_EVENT_CANCELLED');
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('customer_payments', 0);
    }

    public function test_other_user_in_same_company_and_other_company_cannot_read_organizer_event(): void
    {
        $context = $this->stage13IntelligenceContext();
        $headers = ['X-Company-Id' => $context['company']->id];
        $path = '/api/v1/ai/intelligence/calendar/internal-events';
        $id = $this->postJson($path, [
            'title' => 'Organizer only', 'starts_at' => '2026-10-14T10:00:00Z', 'ends_at' => '2026-10-14T11:00:00Z',
        ], $headers)->assertCreated()->json('id');

        $other = User::factory()->create();
        $role = Role::factory()->for($context['company'])->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'intelligence.calendar.manage']));
        CompanyUser::factory()->for($context['company'])->for($other)->create(['role_id' => $role->id]);
        Sanctum::actingAs($other);
        $this->getJson($path, $headers)->assertOk()->assertJsonPath('total', 0);
        $this->getJson("{$path}/{$id}", $headers)->assertNotFound();
        $this->patchJson("{$path}/{$id}", ['title' => 'Intrusion', 'version' => 1], $headers)->assertNotFound();

        $elsewhere = $this->stage13IntelligenceContext();
        $this->getJson("{$path}/{$id}", ['X-Company-Id' => $elsewhere['company']->id])->assertNotFound();
    }

    public function test_internal_calendar_requires_permission_and_valid_offset_interval(): void
    {
        $context = $this->stage13IntelligenceContext(['intelligence.view']);
        $path = '/api/v1/ai/intelligence/calendar/internal-events';
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->postJson($path, ['title' => 'Denied', 'starts_at' => '2026-10-14T10:00:00Z', 'ends_at' => '2026-10-14T11:00:00Z'], $headers)
            ->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');

        $context = $this->stage13IntelligenceContext();
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->postJson($path, ['title' => 'Ambiguous', 'starts_at' => '2026-10-14T10:00:00', 'ends_at' => '2026-10-14T11:00:00'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['starts_at', 'ends_at']);
        $this->postJson($path, ['title' => 'Backwards', 'starts_at' => '2026-10-14T11:00:00Z', 'ends_at' => '2026-10-14T10:00:00Z'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_internal_calendar_requires_authentication_and_active_ai_entitlement(): void
    {
        $path = '/api/v1/ai/intelligence/calendar/internal-events';
        $this->getJson($path, ['X-Company-Id' => '00000000-0000-0000-0000-000000000000'])->assertUnauthorized();
        $context = $this->stage13IntelligenceContext();
        $headers = ['X-Company-Id' => $context['company']->id];
        CompanyEntitlement::query()->where('company_id', $context['company']->id)->where('module_key', 'ai')->update(['is_enabled' => false]);
        app(EntitlementService::class)->forget($context['company']->id);
        $this->getJson($path, $headers)->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
        $this->postJson($path, ['title' => 'Denied', 'starts_at' => '2026-10-14T10:00:00Z', 'ends_at' => '2026-10-14T11:00:00Z'], $headers)
            ->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
        $this->assertDatabaseCount('internal_calendar_events', 0);
    }
}
