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
    public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
    {
        if (parse_url($context->endpoint, PHP_URL_SCHEME) !== 'https' || $context->credential === '') {
            throw new FbrUnavailableException('FBR submission is not configured for this environment.');
        }
        try {
            $response = Http::acceptJson()
                ->withToken($context->credential)
                ->connectTimeout((int) config('services.fbr.connect_timeout', 5))
                ->timeout((int) config('services.fbr.timeout', 15))
                ->withoutRedirecting()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($context->endpoint, $payload);
        } catch (Throwable) {
            throw new FbrUnavailableException('FBR is currently unavailable. The submission can be retried safely.');
        }
        $decodedBody = $response->json();
        $body = is_array($decodedBody) ? $decodedBody : [];
        $metadata = $body !== [] ? Arr::only($body, ['status', 'code', 'message', 'errors', 'timestamp', 'invoiceNumber', 'referenceNumber']) : ['status_code' => $response->status()];
        if ($response->serverError() || $response->redirect()) {
            throw new FbrUnavailableException('FBR is currently unavailable. The submission can be retried safely.');
        }
        if ($response->failed()) {
            return new FbrSubmissionResult(FbrSubmissionStatus::Rejected, null, $metadata, is_string($metadata['message'] ?? null) ? $metadata['message'] : 'FBR rejected the invoice.');
        }
        $reference = $body['invoiceNumber'] ?? $body['referenceNumber'] ?? null;
        $reference = is_string($reference) && trim($reference) !== '' ? $reference : null;
        $status = $reference !== null ? FbrSubmissionStatus::Accepted : FbrSubmissionStatus::Submitted;

        return new FbrSubmissionResult($status, is_string($reference) ? $reference : null, $metadata);
    }
}
