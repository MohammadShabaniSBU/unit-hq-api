<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;

class WhatsappTemplateResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'language' => $this->language,
            'category' => $this->category,
            'header_text' => $this->header_text,
            'body' => $this->body,
            'footer_text' => $this->footer_text,
            'buttons' => $this->buttons,
            'variables' => $this->variables,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'provider_template_id' => $this->provider_template_id,
            'submitted_at' => $this->datetime($this->submitted_at),
            'decided_at' => $this->datetime($this->decided_at),
            'communication_account_id' => $this->communication_account_id,
            'supersedes_id' => $this->supersedes_id,
            'lineage' => $this->when(
                $request->route()?->getActionMethod() === 'show',
                fn (): array => $this->lineagePayload(),
            ),
            'created_by' => $this->created_by,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }

    /**
     * @return list<array{id: int, name: string, language: string, status: string, decided_at: string|null}>
     */
    private function lineagePayload(): array
    {
        /** @var WhatsappTemplate $template */
        $template = $this->resource;

        return $template->lineage()->map(fn (WhatsappTemplate $row): array => [
            'id' => $row->id,
            'name' => $row->name,
            'language' => $row->language,
            'status' => $row->status,
            'decided_at' => $this->datetime($row->decided_at),
        ])->all();
    }
}
