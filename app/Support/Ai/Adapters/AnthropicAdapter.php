<?php

declare(strict_types=1);

namespace App\Support\Ai\Adapters;

use App\Support\Ai\Contracts\AiProviderAdapter;
use App\Support\Ai\Results\AiVerificationResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AnthropicAdapter implements AiProviderAdapter
{
    /**
     * Used only if live discovery via verify() fails — kept intentionally
     * small since it's a fallback, not the source of truth.
     */
    private const FALLBACK_MODELS = [
        'claude-opus-5',
        'claude-sonnet-5',
        'claude-fable-5',
        'claude-haiku-4-5-20251001',
    ];

    private function __construct(
        private readonly ?string $apiKey,
    ) {}

    public static function make(array $credentials): static
    {
        $apiKey = $credentials['api_key'] ?? null;

        return new self(is_string($apiKey) && $apiKey !== '' ? $apiKey : null);
    }

    public function credentialFields(): array
    {
        return [
            'api_key' => ['label' => 'API key', 'secret' => true],
        ];
    }

    public function verify(): AiVerificationResult
    {
        if ($this->apiKey === null) {
            return AiVerificationResult::failed('An API key is required.');
        }

        $baseUrl = rtrim((string) config('ai.providers.anthropic.url', 'https://api.anthropic.com/v1'), '/');

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(15)->get("{$baseUrl}/models", ['limit' => 1000]);
        } catch (Throwable) {
            return AiVerificationResult::failed('Anthropic API is unreachable.');
        }

        if ($response->status() === 401) {
            return AiVerificationResult::failed('The API key was rejected by Anthropic.');
        }

        if (! $response->successful()) {
            return AiVerificationResult::failed('Anthropic returned HTTP '.$response->status().'.');
        }

        $ids = collect($response->json('data', []))
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        return AiVerificationResult::ok($ids !== [] ? $ids : $this->fallbackModels());
    }

    public function fallbackModels(): array
    {
        return self::FALLBACK_MODELS;
    }
}
