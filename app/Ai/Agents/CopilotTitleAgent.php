<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Middleware\MetersUsage;
use App\Models\Employee;
use App\Support\Ai\AiProviderRegistry;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(20)]
#[MaxTokens(64)]
class CopilotTitleAgent implements Agent, HasMiddleware
{
    use Promptable;

    public function __construct(public Employee $employee) {}

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
        return app(AiProviderRegistry::class)->activeModel();
    }

    public function instructions(): Stringable|string
    {
        return 'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.';
    }
}
