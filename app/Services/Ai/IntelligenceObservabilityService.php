<?php

namespace App\Services\Ai;

use App\Models\AiToolRun;
use App\Models\AiUsageRecord;

class IntelligenceObservabilityService
{
    /** @return array<string,mixed> */
    public function report(string $companyId, ?string $from = null, ?string $to = null): array
    {
        $from ??= now()->startOfMonth()->toDateTimeString();
        $to ??= now()->endOfDay()->toDateTimeString();
        $usage = AiUsageRecord::query()->where('company_id', $companyId)->whereBetween('occurred_at', [$from, $to]);
        $tools = AiToolRun::query()->where('company_id', $companyId)->whereBetween('created_at', [$from, $to]);
        $records = (clone $usage)->get();
        $latencies = $records->pluck('metadata')->map(fn ($metadata): int => (int) ($metadata['latency_ms'] ?? 0))->filter(fn (int $value): bool => $value > 0);
        $grouped = fn (string $key): array => $records->groupBy($key)->map(fn ($rows, string $name): array => ['name' => $name, 'requests' => $rows->count(), 'input_tokens' => (int) $rows->sum('input_tokens'), 'output_tokens' => (int) $rows->sum('output_tokens'), 'cost_minor' => (int) $rows->sum('cost_minor')])->values()->all();
        $errors = $records->filter(fn ($record): bool => isset($record->metadata['error_category']))->groupBy(fn ($record): string => (string) $record->metadata['error_category'])->map(fn ($rows, string $category): array => ['category' => $category, 'count' => $rows->count()])->values()->all();

        return ['from' => $from, 'to' => $to, 'requests' => $records->count(), 'input_tokens' => (int) $records->sum('input_tokens'), 'output_tokens' => (int) $records->sum('output_tokens'), 'cost_minor' => (int) $records->sum('cost_minor'), 'average_latency_ms' => $latencies->isEmpty() ? null : intdiv((int) $latencies->sum(), $latencies->count()), 'failures' => $records->filter(fn ($record): bool => isset($record->metadata['error_category']))->count(), 'retrieval_count' => (int) $records->sum(fn ($record): int => (int) ($record->metadata['retrieval_count'] ?? 0)), 'citation_count' => (int) $records->sum(fn ($record): int => (int) ($record->metadata['citation_count'] ?? 0)), 'proposal_count' => (int) $records->sum(fn ($record): int => (int) ($record->metadata['proposal_count'] ?? 0)), 'by_provider' => $grouped('provider'), 'by_model' => $grouped('model'), 'by_operation' => $grouped('operation'), 'errors' => $errors, 'tools' => ['runs' => (clone $tools)->count(), 'failed' => (clone $tools)->where('status', 'FAILED')->count(), 'by_name' => (clone $tools)->selectRaw('tool_name, count(*) as runs')->groupBy('tool_name')->orderBy('tool_name')->get()]];
    }
}
