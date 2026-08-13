<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AiSummaryResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value ?? (string) $this->status,
            'body' => $this->body,
            'highlights' => $this->highlights,
            'locale' => $this->locale,
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_version' => $this->prompt_version,
            'source_digest' => $this->source_digest,
            'source_counts' => $this->source_counts,
            'error_code' => $this->error_code,
            'generated_at' => $this->datetime($this->generated_at),
            'created_at' => $this->datetime($this->created_at),
            'superseded_at' => $this->datetime($this->superseded_at),
        ];
    }
}
