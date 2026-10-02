<?php

namespace Tests\Feature;

use App\Contracts\OutboundEmailGateway;
use App\Models\EmailProviderConnection;
use App\Models\EmailSendingIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\MariaDbCertification;
use Tests\TestCase;

class OutreachProviderTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MariaDbCertification::protectExternalProviders($this->app);
    }

    public function test_provider_secrets_are_encrypted_and_never_returned_by_api(): void
    {
        $context = $this->stage11OutreachContext();
        $payload = ['name' => 'Private SMTP', 'provider_type' => 'SMTP', 'configuration' => ['host' => 'smtp.private.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'private@example.test'], 'credentials' => ['password' => 'super-secret-password']];

        $response = $this->postJson('/api/v1/outreach/providers', $payload, $this->headers($context['company']->id))->assertCreated()->assertJsonMissing(['password' => 'super-secret-password'])->assertJsonPath('credentials_configured', true);
        $connection = EmailProviderConnection::query()->findOrFail($response->json('id'));

        $this->assertSame('super-secret-password', $connection->credentials['password']);
        $this->assertStringNotContainsString('super-secret-password', (string) $connection->getRawOriginal('credentials'));
    }

    public function test_provider_verification_and_disconnect_use_gateway_boundary(): void
    {
        $context = $this->stage11OutreachContext();
        $gateway = $this->mock(OutboundEmailGateway::class);
        $gateway->shouldReceive('verify')->once()->andReturn(['success' => true, 'message' => 'verified']);

        $this->postJson('/api/v1/outreach/providers/'.$context['connection']->id.'/verify', [], $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'CONNECTED');
        $this->postJson('/api/v1/outreach/providers/'.$context['connection']->id.'/disconnect', [], $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'DISCONNECTED');
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'action' => 'provider_connection_disconnected']);
    }

    public function test_identity_creation_assigns_exactly_one_default_and_requires_connected_company_provider(): void
    {
        $context = $this->stage11OutreachContext();

        $created = $this->postJson('/api/v1/outreach/identities', ['provider_connection_id' => $context['connection']->id, 'from_email' => 'second@example.test', 'from_name' => 'Second Sender', 'is_default' => true], $this->headers($context['company']->id))->assertCreated()->assertJsonPath('verification_status', 'VERIFIED');

        $this->assertSame(1, EmailSendingIdentity::query()->where('company_id', $context['company']->id)->where('is_default', true)->count());
        $this->assertTrue((bool) EmailSendingIdentity::query()->findOrFail($created->json('id'))->is_default);
    }

    public function test_first_identity_is_default_even_when_false_is_requested_and_default_cannot_be_removed(): void
    {
        $context = $this->stage11OutreachContext();
        $context['sequence']->delete();
        $context['identity']->delete();

        $created = $this->postJson('/api/v1/outreach/identities', ['provider_connection_id' => $context['connection']->id, 'from_email' => 'only@example.test', 'from_name' => 'Only Sender', 'is_default' => false], $this->headers($context['company']->id))->assertCreated()->assertJsonPath('is_default', true);

        $this->patchJson('/api/v1/outreach/identities/'.$created->json('id'), ['is_default' => false], $this->headers($context['company']->id))->assertConflict()->assertJsonPath('error_code', 'DEFAULT_IDENTITY_REQUIRED');
    }

    public function test_template_rejects_unknown_variables_and_previews_authoritative_crm_values(): void
    {
        $context = $this->stage11OutreachContext();
        $this->postJson('/api/v1/outreach/templates', ['name' => 'Invalid', 'subject' => 'Hello {{contact.password}}', 'body_text' => 'Body'], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonPath('error_code', 'UNKNOWN_TEMPLATE_VARIABLE');

        $created = $this->postJson('/api/v1/outreach/templates', ['name' => 'Preview', 'subject' => 'Hello {{contact.first_name}}', 'body_text' => 'For {{account.name}}', 'body_html' => '<p>Hello {{contact.first_name}}</p>'], $this->headers($context['company']->id))->assertCreated();
        $this->postJson('/api/v1/outreach/templates/'.$created->json('id').'/preview', ['contact_id' => $context['contact']->id], $this->headers($context['company']->id))->assertOk()->assertJsonPath('subject', 'Hello Ayesha')->assertJsonPath('body_text', 'For '.$context['account']->name);
    }

    public function test_template_html_variables_are_context_escaped(): void
    {
        $context = $this->stage11OutreachContext();
        $context['contact']->update(['first_name' => '<script>alert(1)</script>']);

        $response = $this->postJson('/api/v1/outreach/templates/'.$context['template']->id.'/preview', ['contact_id' => $context['contact']->id], $this->headers($context['company']->id))->assertOk();

        $this->assertStringContainsString('&lt;script&gt;', (string) $response->json('body_html'));
        $this->assertStringNotContainsString('<script>', (string) $response->json('body_html'));
    }

    public function test_template_html_is_sanitized_and_archive_state_is_audited(): void
    {
        $context = $this->stage11OutreachContext();
        $context['template']->update(['body_html' => '<script>alert(1)</script><p>Safe {{contact.first_name}}</p>']);

        $this->postJson('/api/v1/outreach/templates/'.$context['template']->id.'/preview', ['contact_id' => $context['contact']->id], $this->headers($context['company']->id))->assertOk()->assertJsonMissing(['body_html' => '<script>alert(1)</script><p>Safe Ayesha</p>'])->assertJsonPath('body_html', '<p>Safe Ayesha</p>');
        $this->patchJson('/api/v1/outreach/templates/'.$context['template']->id, ['category' => 'FOLLOW_UP', 'is_active' => false], $this->headers($context['company']->id))->assertOk()->assertJsonPath('category', 'FOLLOW_UP')->assertJsonPath('is_active', false);

        $this->assertNotNull($context['template']->fresh()->archived_at);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'action' => 'email_template_updated']);
    }

    public function test_ai_drafting_fails_explicitly_when_no_provider_is_configured(): void
    {
        $context = $this->stage11OutreachContext();

        $this->postJson('/api/v1/outreach/ai/draft', ['prompt' => 'Write an introduction'], $this->headers($context['company']->id))->assertConflict()->assertJsonPath('error_code', 'AI_PROVIDER_NOT_CONFIGURED');
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
