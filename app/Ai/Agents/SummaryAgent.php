<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Middleware\MetersUsage;
use App\Models\Employee;
use App\Support\Ai\AiProviderRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(90)]
#[MaxTokens(900)]
class SummaryAgent implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public Employee $employee,
        public string $instructionsText = 'You write concise CRM summaries from structured context only.',
    ) {}

    public function middleware(): array
    {
        return [
            MetersUsage::class,
        ];
    }

    public function provider(): ?string
    {
        return app(AiProviderRegistry::class)->applyActiveCredentials();
    }

    public function model(): ?string
    {
        $configured = config('ai.summaries.model');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return app(AiProviderRegistry::class)->activeModel();
    }

    public function instructions(): Stringable|string
    {
        return $this->instructionsText;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->required(),
            'highlights' => $schema->array()->items(
                $schema->object([
                    'key' => $schema->string()->required(),
                    'label_key' => $schema->string()->nullable(),
                    'value' => $schema->string()->required(),
                ])
            )->nullable(),
        ];
    }
}
