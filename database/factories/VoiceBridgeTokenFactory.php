<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\VoiceBridgeToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VoiceBridgeToken>
 */
class VoiceBridgeTokenFactory extends Factory
{
    protected $model = VoiceBridgeToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => Str::random(40),
            'secret' => Str::random(40),
            'secret_previous' => null,
            'site_id' => Site::factory(),
            'phone_number' => '+1'.fake()->numerify('##########'),
            'label' => null,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }
}
