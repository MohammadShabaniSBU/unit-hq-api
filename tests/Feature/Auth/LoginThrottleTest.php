<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function sixth_attempt_is_throttled(): void
    {
        $payload = [
            'email' => 'staff@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', $payload)->assertStatus(422);
        }

        $this->postJson('/api/login', $payload)
            ->assertStatus(429)
            ->assertJson([
                'message' => 'errors.too_many_attempts',
                'data' => null,
            ]);
    }

    #[Test]
    public function throttle_keyed_per_email_and_ip(): void
    {
        $locked = [
            'email' => 'locked@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', $locked)->assertStatus(422);
        }

        $this->postJson('/api/login', $locked)->assertStatus(429);

        $this->postJson('/api/login', [
            'email' => 'other@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }
}
