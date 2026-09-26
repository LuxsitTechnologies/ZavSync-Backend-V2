<?php

namespace App\Services\Ai;

use App\Contracts\AiUsageMeter;
use App\Contracts\EmbeddingProvider;
use App\Models\KnowledgeChunk;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class KnowledgeRetrievalService
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly AiProviderConfigurationService $configurations,
        private readonly AiUsageMeter $usage,
        private readonly PromptSecurityService $security,
        private readonly PlatformAccessService $access,
    ) {}

    /** @return array<int, array<string, mixed>> */
    /** @param array{source_ids?:array<int,string>,chunk_ids?:array<int,string>} $filters */
    public function search(User $user, string $companyId, string $query, int $limit = 6, array $filters = []): array
    {
        if (! $user->hasCompanyPermission($companyId, 'ai.knowledge.view')) {
            return [];
        }
        $permissions = $this->access->permissionNames($user, $companyId);
        $chunksQuery = KnowledgeChunk::query()
            ->with('source.document')
            ->where('company_id', $companyId)
            ->when($filters['source_ids'] ?? [], fn ($chunks, array $sourceIds) => $chunks->whereIn('knowledge_source_id', $sourceIds))
            ->when($filters['chunk_ids'] ?? [], fn ($chunks, array $chunkIds) => $chunks->whereIn('id', $chunkIds))
            ->whereHas('source', function ($source) use ($permissions): void {
                $source->where('status', 'READY');
                if (! in_array('*', $permissions, true)) {
                    $source->whereIn('access_permission', $permissions);
                }
            });
        $chunks = $chunksQuery
            ->latest()
            ->limit(500)
            ->get()
            ->filter(function (KnowledgeChunk $chunk): bool {
                $source = $chunk->source;
                if ($source->version !== $chunk->source_version) {
                    return false;
                }

                return $source->source_type !== 'DOCUMENT' || $source->document !== null;
            });
        if ($chunks->isEmpty()) {
            return [];
        }

        $configuration = $this->configurations->requireEnabled($companyId);
        $embedding = $this->embeddings->embed([$query], $configuration);
        $queryVector = $embedding->vectors[0] ?? [];
        $this->usage->record($companyId, $user->id, [
            'provider' => $embedding->provider,
            'model' => $embedding->model,
            'operation' => 'RETRIEVAL',
            'input_tokens' => $embedding->inputTokens,
            'output_tokens' => 0,
            'cost_minor' => $embedding->costMinor,
            'metadata' => ['latency_ms' => $embedding->latencyMs],
        ]);
        $terms = collect(preg_split('/[^\pL\pN]+/u', Str::lower($query)) ?: [])->filter(fn (string $term) => Str::length($term) >= 3)->unique()->values();

        return $chunks
            ->filter(function (KnowledgeChunk $chunk) use ($companyId, $user): bool {
                if ($this->security->isSafeKnowledge($chunk->content)) {
                    return true;
                }
                SecurityEvent::query()->create(['company_id' => $companyId, 'user_id' => $user->id, 'type' => 'AI_KNOWLEDGE_INJECTION_SKIPPED', 'result' => 'DENIED', 'metadata' => ['knowledge_source_id' => $chunk->knowledge_source_id, 'chunk_hash' => $chunk->content_hash]]);

                return false;
            })
            ->map(function (KnowledgeChunk $chunk) use ($queryVector, $terms): array {
                $haystack = Str::lower($chunk->content);
                $lexicalScore = $terms->sum(fn (string $term): int => substr_count($haystack, $term) * 1000);
                $vectorScore = $this->dotProduct($queryVector, $chunk->embedding ?? []);

                return ['chunk' => $chunk, 'score' => $lexicalScore + $vectorScore];
            })
            ->sortByDesc('score')
            ->take(max(1, min(12, $limit)))
            ->values()
            ->map(function (array $ranked, int $index): array {
                /** @var KnowledgeChunk $chunk */
                $chunk = $ranked['chunk'];

                return [
                    'ordinal' => $index + 1,
                    'source_id' => $chunk->knowledge_source_id,
                    'chunk_id' => $chunk->id,
                    'title' => $chunk->source->title,
                    'content' => $chunk->content,
                    'excerpt' => Str::limit($chunk->content, 500, '…'),
                    'locator' => $chunk->locator ?? [],
                    'source_version' => $chunk->source_version,
                    'access_permission' => $chunk->source->access_permission,
                    'score' => $ranked['score'],
                ];
            })->all();
    }

    /** @param array<int, int> $left @param array<int, int> $right */
    private function dotProduct(array $left, array $right): int
    {
        return Collection::make($left)->map(fn (int $value, int $index): int => $value * ($right[$index] ?? 0))->sum();
    }
}
