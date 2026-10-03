<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'https://frontend.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cors.allowed_origins', [self::FRONTEND_ORIGIN]);
    }

    public function test_approved_frontend_login_preflight_returns_exact_origin_and_credentials(): void
    {
        $this->options('/api/v1/auth/login', [], [
            'Origin' => self::FRONTEND_ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_approved_frontend_can_initialize_sanctum_csrf_cookie(): void
    {
        $response = $this->get('/sanctum/csrf-cookie', [
            'Origin' => self::FRONTEND_ORIGIN,
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $cookieNames = array_map(static fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());
        $this->assertContains('XSRF-TOKEN', $cookieNames);
    }

    public function test_unapproved_origin_is_not_granted_credentialed_cors(): void
    {
        $unapprovedOrigin = 'https://unapproved.example.test';

        $response = $this->options('/api/v1/auth/login', [], [
            'Origin' => $unapprovedOrigin,
            'Access-Control-Request-Method' => 'POST',
        ])->assertNoContent();

        $this->assertNotSame($unapprovedOrigin, $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_unauthenticated_api_request_remains_safe_json_401_with_cors(): void
    {
        $this->get('/api/v1/employee/me', [
            'Origin' => self::FRONTEND_ORIGIN,
        ])->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN)
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_authenticated_sanctum_api_request_still_succeeds_with_cors(): void
    {
        [, $company] = $this->actingAsCompanyUser(['employee.self.view']);

        $this->get('/api/v1/employee/me', [
            'Origin' => self::FRONTEND_ORIGIN,
            'X-Company-Id' => $company->id,
        ])->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN)
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertJsonPath('linked', false);
    }
}
