<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PerimeterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function businessRoutesProvider(): array
    {
        return [
            ['GET', '/api/contracts'],
            ['GET', '/api/invoices'],
            ['POST', '/api/payments/1/reverse'],
            ['GET', '/api/units'],
            ['PATCH', '/api/settings/billing'],
            ['POST', '/api/tax-rates'],
            ['GET', '/api/activities'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function publicRoutesProvider(): array
    {
        return [
            ['POST', '/api/login'],
            ['POST', '/api/webhooks/stripe/test-token'],
            ['POST', '/api/webhooks/esign/test-token'],
            ['POST', '/api/webhooks/access/test-token'],
            ['POST', '/api/webhooks/brevo/test-token'],
            ['POST', '/api/webhooks/brevo/test-token/inbound'],
            ['GET', '/api/comms/unsubscribe/not-a-real-token'],
            ['GET', '/api/public/template-assets/'.str_repeat('a', 64).'/logo.png'],
            ['GET', '/api/offers/token/not-a-real-token'],
            ['POST', '/api/offer-options/1/select'],
            ['GET', '/api/pay/not-a-real-token'],
            ['POST', '/api/pay/not-a-real-token/intent'],
            ['GET', '/api/legal-entities/1/stripe/public-key'],
        ];
    }

    #[DataProvider('businessRoutesProvider')]
    public function test_business_routes_require_authentication(string $method, string $uri): void
    {
        $response = $this->json($method, $uri);

        $response->assertUnauthorized();
    }

    #[DataProvider('publicRoutesProvider')]
    public function test_public_routes_remain_public(string $method, string $uri): void
    {
        $response = $this->json($method, $uri, []);

        $this->assertNotSame(
            401,
            $response->status(),
            "{$method} {$uri} returned 401 — should remain on the public allowlist",
        );
    }

    public function test_unauthenticated_api_response_is_json(): void
    {
        $response = $this->getJson('/api/contracts');

        $response->assertUnauthorized();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        $this->assertIsArray($response->json());
    }
}
