<?php

namespace App\Services\Ai;

use App\Contracts\AiUsageMeter;
use App\Contracts\EmbeddingProvider;
use App\Exceptions\PlatformException;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeIngestionRun;
use App\Models\KnowledgeSource;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class KnowledgeIngestionService
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly AiProviderConfigurationService $configurations,
        private readonly AiUsageMeter $usage,
        private readonly EntitlementService $entitlements,
    ) {}

    public function ingest(string $runId): KnowledgeIngestionRun
    {
        $run = KnowledgeIngestionRun::query()->with('source.document')->findOrFail($runId);
        if ($run->status === 'COMPLETED') {
            return $run;
        }

        $run->update(['status' => 'PROCESSING', 'attempt' => $run->attempt + 1, 'started_at' => now(), 'failure_code' => null, 'failure_message' => null]);
        $source = $run->source;

        try {
            if ($source->version !== $run->source_version) {
                throw new PlatformException('KNOWLEDGE_SOURCE_VERSION_CHANGED', 'This ingestion run was superseded by a newer source version.', 409);
            }

            $configuration = $this->configurations->requireEnabled($run->company_id);
            $content = $this->extract($source);
            $chunks = $this->chunks($content);
            if ($chunks === []) {
                throw new PlatformException('KNOWLEDGE_SOURCE_EMPTY', 'The knowledge source does not contain indexable text.', 422);
            }

            $this->usage->assertWithinLimit($run->company_id, 'EMBEDDING');
            $result = $this->embeddings->embed($chunks, $configuration);
            if (count($result->vectors) !== count($chunks)) {
                throw new PlatformException('AI_PROVIDER_INVALID_RESPONSE', 'The embedding provider returned an invalid vector count.', 502);
            }
            $existingChunks = KnowledgeChunk::query()->where('company_id', $run->company_id)->where('knowledge_source_id', '!=', $source->id)->count();
            $this->entitlements->assertWithinLimit($run->company_id, 'knowledge_chunks', $existingChunks, count($chunks));

            DB::transaction(function () use ($run, $source, $chunks, $result): void {
                $lockedSource = KnowledgeSource::query()->where('company_id', $run->company_id)->lockForUpdate()->findOrFail($source->id);
                if ($lockedSource->version !== $run->source_version) {
                    throw new PlatformException('KNOWLEDGE_SOURCE_VERSION_CHANGED', 'This ingestion run was superseded by a newer source version.', 409);
                }
                KnowledgeChunk::query()->where('company_id', $run->company_id)->where('knowledge_source_id', $source->id)->delete();
                foreach ($chunks as $index => $chunk) {
                    KnowledgeChunk::query()->create([
                        'company_id' => $run->company_id,
                        'knowledge_source_id' => $source->id,
                        'source_version' => $run->source_version,
                        'chunk_index' => $index,
                        'content' => $chunk,
                        'content_hash' => hash('sha256', $chunk),
                        'locator' => ['chunk' => $index + 1, 'source_version' => $run->source_version],
                        'embedding' => $result->vectors[$index],
                    ]);
                }
                $lockedSource->update(['status' => 'READY', 'chunk_count' => count($chunks), 'indexed_at' => now(), 'failed_at' => null]);
                $run->update(['status' => 'COMPLETED', 'chunk_count' => count($chunks), 'completed_at' => now()]);
            });

            $this->usage->record($run->company_id, $run->requested_by, [
                'provider' => $result->provider,
                'model' => $result->model,
                'operation' => 'EMBEDDING',
                'input_tokens' => $result->inputTokens,
                'output_tokens' => 0,
                'cost_minor' => $result->costMinor,
                'metadata' => ['knowledge_source_id' => $source->id, 'source_version' => $source->version, 'latency_ms' => $result->latencyMs],
            ]);

            return $run->fresh();
        } catch (Throwable $exception) {
            $code = $exception instanceof PlatformException ? $exception->errorCode : 'KNOWLEDGE_INGESTION_FAILED';
            $message = $exception instanceof PlatformException ? $exception->getMessage() : 'The knowledge source could not be indexed.';
            $run->update(['status' => 'FAILED', 'failure_code' => $code, 'failure_message' => Str::limit($message, 2000, ''), 'completed_at' => now()]);
            if ($code !== 'KNOWLEDGE_SOURCE_VERSION_CHANGED') {
                $source->update(['status' => 'FAILED', 'failed_at' => now()]);
            }
            throw $exception;
        }
    }

    private function extract(KnowledgeSource $source): string
    {
        if ($source->source_type === 'NOTE') {
            return trim((string) $source->content);
        }
        $document = $source->document;
        if ($document === null || $document->company_id !== $source->company_id) {
            throw new PlatformException('KNOWLEDGE_DOCUMENT_UNAVAILABLE', 'The source document is no longer available.', 409);
        }
        $allowed = ['text/plain', 'text/csv', 'text/markdown', 'application/json'];
        if (! in_array($document->mime_type, $allowed, true)) {
            throw new PlatformException('KNOWLEDGE_MIME_UNSUPPORTED', 'This document type requires a production extraction provider before it can be indexed.', 422);
        }
        if (! Storage::disk($document->storage_disk)->exists($document->storage_key)) {
            throw new PlatformException('KNOWLEDGE_DOCUMENT_UNAVAILABLE', 'The source document file is unavailable.', 409);
        }

        return trim(Storage::disk($document->storage_disk)->get($document->storage_key));
    }

    /** @return array<int, string> */
    private function chunks(string $content): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $content));
        $chunks = [];
        $offset = 0;
        while ($offset < Str::length($normalized)) {
            $chunk = trim(Str::substr($normalized, $offset, 1200));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
            $offset += 1050;
        }

        return $chunks;
    }
}
