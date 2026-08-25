<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\Ai\Tools\ProposableTool;
use App\Support\Ai\Tools\ToolRegistry;
use Illuminate\Http\Request;

class AiAgentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'model' => $this->model,
            'write_tools' => $this->writeTools(),
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     required_verification: string,
     *     proposable: bool,
     *     mode: string,
     *     max_per_conversation: int|null,
     *     max_per_day: int|null,
     *     min_verification: string|null
     * }>
     */
    private function writeTools(): array
    {
        $definitions = app(AgentRegistry::class);
        if (! $definitions->has($this->key)) {
            return [];
        }

        $tools = app(ToolRegistry::class);
        $policies = $this->relationLoaded('writePolicies')
            ? $this->writePolicies->keyBy('tool_key')
            : $this->writePolicies()->get()->keyBy('tool_key');

        $rows = [];
        foreach ($definitions->get($this->key)->toolKeys() as $key) {
            if (! $tools->has($key)) {
                continue;
            }

            $tool = $tools->get($key);
            if (! $tool->isWrite()) {
                continue;
            }

            $policy = $policies->get($key);
            $rows[] = [
                'key' => $key,
                'required_verification' => $tool->requiredVerification()->value,
                'proposable' => $tool instanceof ProposableTool,
                'mode' => $policy?->mode->value ?? WritePolicyMode::Commit->value,
                'max_per_conversation' => $policy?->max_per_conversation,
                'max_per_day' => $policy?->max_per_day,
                'min_verification' => $policy?->min_verification?->value,
            ];
        }

        return $rows;
    }
}
