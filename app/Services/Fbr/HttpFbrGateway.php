<?php

namespace App\Services\Fbr;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrUnavailableException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpFbrGateway implements FbrGateway
{
    public function submit(array $payload, string $idempotencyKey): FbrSubmissionResult
    {
        $endpoint = config('services.fbr.endpoint');
        $token = config('services.fbr.token');
        if (! is_string($endpoint) || $endpoint === '' || ! is_string($token) || $token === '') {
            throw new FbrUnavailableException('FBR submission is not configured for this environment.');
        }
        try {
            $response = Http::acceptJson()->withToken($token)->timeout((int) config('services.fbr.timeout', 15))->withHeaders(['Idempotency-Key' => $idempotencyKey])->post($endpoint, $payload);
        } catch (Throwable $exception) {
            throw new FbrUnavailableException('FBR is currently unavailable. The submission can be retried safely.', previous: $exception);
        }
        $decodedBody = $response->json();
        $body = is_array($decodedBody) ? $decodedBody : [];
        $metadata = $body !== [] ? Arr::only($body, ['status', 'code', 'message', 'errors', 'timestamp', 'invoiceNumber', 'referenceNumber']) : ['status_code' => $response->status()];
        if ($response->serverError()) {
            throw new FbrUnavailableException('FBR is currently unavailable. The submission can be retried safely.');
        }
        if ($response->failed()) {
            return new FbrSubmissionResult(FbrSubmissionStatus::Rejected, null, $metadata, (string) ($metadata['message'] ?? 'FBR rejected the invoice.'));
        }
        $reference = $body['invoiceNumber'] ?? $body['referenceNumber'] ?? null;
        $status = ($body['status'] ?? null) === 'accepted' || $reference !== null ? FbrSubmissionStatus::Accepted : FbrSubmissionStatus::Submitted;

        return new FbrSubmissionResult($status, is_string($reference) ? $reference : null, $metadata);
    }
}
