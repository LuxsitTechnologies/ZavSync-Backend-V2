<?php

namespace App\Services\Crm;

use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmScoreEvent;
use App\Models\CrmScoreRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CrmScoringService
{
    /** @return array{score:int,events:array<int, array<string, mixed>>} */
    public function recalculate(Model $record, string $companyId, int $userId): array
    {
        return DB::transaction(function () use ($record, $companyId, $userId): array {
            $targetType = $record instanceof CrmLead ? 'LEAD' : 'DEAL';
            $rules = CrmScoreRule::query()->where('company_id', $companyId)->where('target_type', $targetType)->where('is_active', true)->orderBy('position')->get();
            CrmScoreEvent::query()->where('company_id', $companyId)->where('scoreable_type', $record->getMorphClass())->where('scoreable_id', $record->getKey())->delete();
            $events = [];
            $score = 0;

            foreach ($rules as $rule) {
                $actual = $this->value($record, $rule->field);
                if (! $this->matches($actual, $rule->operator, $rule->comparison_value)) {
                    continue;
                }
                $score += $rule->points;
                $event = CrmScoreEvent::query()->create(['company_id' => $companyId, 'scoreable_type' => $record->getMorphClass(), 'scoreable_id' => $record->getKey(), 'score_rule_id' => $rule->id, 'points' => $rule->points, 'reason' => $rule->name, 'details' => ['field' => $rule->field, 'operator' => $rule->operator, 'expected' => $rule->comparison_value, 'actual' => $actual], 'calculated_at' => now(), 'created_by' => $userId]);
                $events[] = $event->toArray();
            }

            $score = max(0, $score);
            if ($record instanceof CrmLead) {
                $record->update(['score' => $score, 'updated_by' => $userId]);
            }

            return ['score' => $score, 'events' => $events];
        });
    }

    private function value(Model $record, string $field): mixed
    {
        return match ($field) {
            'activity_count' => $record->activities()->count(),
            'days_inactive' => (int) $record->updated_at->diffInDays(now()),
            'stage' => $record instanceof CrmDeal ? $record->stage()->value('name') : null,
            default => $record->getAttribute($field),
        };
    }

    private function matches(mixed $actual, string $operator, ?string $expected): bool
    {
        return match ($operator) {
            'NOT_EMPTY' => $actual !== null && $actual !== '',
            'EQUALS' => mb_strtolower((string) $actual) === mb_strtolower((string) $expected),
            'GREATER_OR_EQUAL' => is_numeric($actual) && (int) $actual >= (int) $expected,
            'LESS_OR_EQUAL' => is_numeric($actual) && (int) $actual <= (int) $expected,
            default => false,
        };
    }
}
