<?php

namespace Tests\Feature\Stage15;

use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrUnavailableException;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\HttpFbrGateway;
use App\Services\Migration\HistoricalResponseSanitizer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FbrTransportSecurityTest extends TestCase
{
    public function test_transport_rejects_plain_http_before_sending_credentials(): void
    {
        Http::fake();
        try {
            app(HttpFbrGateway::class)->submit([], 'fixture', new FbrSubmissionContext('http://fbr.example.test/submit', 'fixture-secret', 'SANDBOX'));
            $this->fail('An insecure endpoint was accepted.');
        } catch (FbrUnavailableException $exception) {
            $this->assertStringNotContainsString('fixture-secret', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_transport_preserves_tls_verification_disables_redirects_and_uses_scoped_context(): void
    {
        Http::fake(['https://fbr.example.test/submit' => Http::response(['invoiceNumber' => 'FBR-CERT-FIXTURE'], 200)]);
        Http::beforeSending(function ($request, array $options): void {
            $this->assertNotSame(false, $options['verify'] ?? true);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(15, $options['timeout']);
        });

        $result = app(HttpFbrGateway::class)->submit(['invoiceRefNo' => 'PKF-1'], 'pkfbr:document:key', new FbrSubmissionContext('https://fbr.example.test/submit', 'fixture-secret', 'SANDBOX'));

        $this->assertSame(FbrSubmissionStatus::Accepted, $result->status);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', 'pkfbr:document:key') && $request->hasHeader('Authorization', 'Bearer fixture-secret'));
    }

    public function test_success_without_reference_is_pending_confirmation_and_redirect_is_unavailable(): void
    {
        Http::fake(['https://fbr.example.test/submit' => Http::sequence()->push(['status' => 'accepted'], 200)->push('', 302, ['Location' => 'https://elsewhere.example.test'])]);
        $gateway = app(HttpFbrGateway::class);
        $context = new FbrSubmissionContext('https://fbr.example.test/submit', 'fixture-secret', 'SANDBOX');
        $this->assertSame(FbrSubmissionStatus::Submitted, $gateway->submit([], 'one', $context)->status);
        $this->expectException(FbrUnavailableException::class);
        $gateway->submit([], 'two', $context);
    }

    public function test_historical_and_provider_response_sanitizer_excludes_nested_credentials_and_redacts_free_text_secrets(): void
    {
        $result = app(HistoricalResponseSanitizer::class)->sanitize([
            'status' => 'success', 'Authorization' => 'must-not-persist',
            'buyer' => ['cnic' => 'must-not-persist'],
            'message' => 'Bearer private-bearer token=private-token password=private-password user@example.test',
            'validationResponse' => ['status' => 'valid', 'credential' => 'must-not-persist', 'invoiceStatuses' => [['code' => '00', 'token' => 'must-not-persist']]],
        ]);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['must-not-persist', 'private-bearer', 'private-token', 'private-password', 'user@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertSame('valid', $result['validationResponse']['status']);
        $this->assertSame('00', $result['validationResponse']['invoiceStatuses'][0]['code']);
    }
}
