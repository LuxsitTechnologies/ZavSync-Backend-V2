<?php

namespace Tests\Feature;

use App\Contracts\OutboundEmailGateway;
use App\Models\EmailProviderConnection;
use App\Models\OutreachMessage;
use App\Services\Outreach\ProviderSendResult;
use App\Services\Outreach\SmtpEmailGateway;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbExternalProviderGuardTest extends TestCase
{
    #[DataProvider('smtpOperations')]
    public function test_blocks_smtp_operations_before_transport_construction(string $binding, string $operation): void
    {
        $this->mock(MailManager::class)->shouldNotReceive('build');
        MariaDbCertification::protectExternalProviders($this->app);
        $gateway = $this->app->make($binding);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Real SMTP is disabled during certification; bind a test gateway.');
        $gateway->{$operation}(new EmailProviderConnection, new OutreachMessage);
    }

    /** @return array<string, array{class-string, string}> */
    public static function smtpOperations(): array
    {
        return [
            'interface verification' => [OutboundEmailGateway::class, 'verify'],
            'interface sending' => [OutboundEmailGateway::class, 'send'],
            'concrete verification' => [SmtpEmailGateway::class, 'verify'],
            'concrete sending' => [SmtpEmailGateway::class, 'send'],
        ];
    }

    #[DataProvider('bindingOrders')]
    public function test_explicit_gateway_fake_remains_usable_before_or_after_guard(bool $bindBeforeGuard): void
    {
        $gateway = \Mockery::mock(OutboundEmailGateway::class);
        $provider = new EmailProviderConnection;
        $message = new OutreachMessage;
        $result = new ProviderSendResult('isolated-test-message');
        $gateway->shouldReceive('send')->once()->with($provider, $message)->andReturn($result);
        $gateway->shouldReceive('verify')->once()->with($provider)->andReturn(['success' => true, 'message' => 'fake']);
        if ($bindBeforeGuard) {
            $this->app->instance(OutboundEmailGateway::class, $gateway);
        }
        MariaDbCertification::protectExternalProviders($this->app);
        if (! $bindBeforeGuard) {
            $this->app->instance(OutboundEmailGateway::class, $gateway);
        }

        $resolved = $this->app->make(OutboundEmailGateway::class);

        $this->assertSame($gateway, $resolved);
        $this->assertSame($result, $resolved->send($provider, $message));
        $this->assertSame(['success' => true, 'message' => 'fake'], $resolved->verify($provider));
    }

    /** @return array<string, array{bool}> */
    public static function bindingOrders(): array
    {
        return ['before guard' => [true], 'after guard' => [false]];
    }

    public function test_unfaked_http_is_blocked(): void
    {
        MariaDbCertification::protectExternalProviders($this->app);

        $this->expectException(StrayRequestException::class);
        Http::get('https://certification-network-must-not-run.invalid');
    }

    public function test_explicit_http_fake_remains_usable(): void
    {
        MariaDbCertification::protectExternalProviders($this->app);
        Http::fake(['https://certification.example.test/status' => Http::response(['status' => 'fake'])]);

        $response = Http::get('https://certification.example.test/status');

        $this->assertSame(['status' => 'fake'], $response->json());
        Http::assertSentCount(1);
    }
}
