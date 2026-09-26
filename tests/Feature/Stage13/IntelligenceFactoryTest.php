<?php

namespace Tests\Feature\Stage13;

use App\Models\AiProviderReconciliation;
use App\Models\AnomalyResult;
use App\Models\CalendarEvent;
use App\Models\CalendarProviderConnection;
use App\Models\IntelligenceBriefing;
use App\Models\IntelligenceForecast;
use App\Models\IntelligenceScenario;
use App\Models\OperationalPrioritySignal;
use App\Models\OperationalSignalEvent;
use App\Models\ScheduledIntelligenceRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntelligenceFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_stage_thirteen_factories_create_company_scoped_records(): void
    {
        $context = $this->stage13IntelligenceContext();
        $signal = OperationalPrioritySignal::factory()->for($context['company'])->create();
        $connection = CalendarProviderConnection::factory()->for($context['company'])->for($context['user'])->create(['created_by' => $context['user']->id]);
        $records = [
            $signal,
            OperationalSignalEvent::factory()->for($context['company'])->for($signal, 'signal')->create(['actor_id' => $context['user']->id]),
            AnomalyResult::factory()->for($context['company'])->create(),
            IntelligenceForecast::factory()->for($context['company'])->create(),
            IntelligenceScenario::factory()->for($context['company'])->create(['created_by' => $context['user']->id]),
            IntelligenceBriefing::factory()->for($context['company'])->create(['created_by' => $context['user']->id]),
            ScheduledIntelligenceRun::factory()->for($context['company'])->create(),
            $connection,
            CalendarEvent::factory()->for($context['company'])->for($connection, 'connection')->create(),
            AiProviderReconciliation::factory()->for($context['company'])->create(['imported_by' => $context['user']->id]),
        ];

        $this->assertCount(10, $records);
        foreach ($records as $record) {
            $this->assertSame($context['company']->id, $record->company_id);
            $this->assertTrue($record->exists);
        }
    }
}
