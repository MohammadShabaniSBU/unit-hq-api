<?php

declare(strict_types=1);

namespace App\Support\Ai\Eval;

final readonly class EvalFixture
{
    /**
     * @param  array<string, mixed>  $principal
     * @param  list<array<string, mixed>>  $turns
     * @param  list<string>  $tags
     * @param  array<string, string>  $writePolicies  Fixture-conversation only. Never touches AiAgentSeeder.
     */
    public function __construct(
        public string $id,
        public string $agent,
        public string $channel,
        public string $locale,
        public array $principal,
        public array $turns,
        public array $tags,
        public bool $liveOnly,
        public string $path,
        public array $writePolicies = [],
    ) {}

    public function slug(): string
    {
        $parts = explode('/', $this->id, 2);

        return $parts[1] ?? $this->id;
    }
}
