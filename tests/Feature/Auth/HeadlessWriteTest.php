<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HeadlessWriteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('scheduledCommandsProvider')]
    public function scheduled_commands_run_without_employee(string $command, array $parameters): void
    {
        $this->assertGuest();

        $exit = Artisan::call($command, $parameters);

        $this->assertSame(0, $exit, Artisan::output());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function scheduledCommandsProvider(): array
    {
        return [
            'billing:run' => ['billing:run', ['--dry-run' => true]],
            'contracts:activate' => ['contracts:activate', []],
            'delinquency:run' => ['delinquency:run', []],
            'access:sync' => ['access:sync', ['--dry-run' => true]],
            'automations:run-scheduled' => ['automations:run-scheduled', []],
        ];
    }

    #[Test]
    #[DataProvider('webhookRoutesProvider')]
    public function webhooks_run_without_employee(string $method, string $uri): void
    {
        $this->assertGuest();

        $response = $this->json($method, $uri, []);

        $this->assertNotSame(
            401,
            $response->status(),
            "{$method} {$uri} returned 401 — webhook must remain public (got {$response->status()})",
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function webhookRoutesProvider(): array
    {
        return [
            'stripe' => ['POST', '/api/webhooks/stripe/test-token'],
            'esign' => ['POST', '/api/webhooks/esign/test-token'],
            'access' => ['POST', '/api/webhooks/access/test-token'],
            'delivery' => ['POST', '/api/webhooks/brevo/test-token'],
            'delivery_inbound' => ['POST', '/api/webhooks/brevo/test-token/inbound'],
        ];
    }
}
