<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class InvoiceSeriesResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_entity_id' => $this->legal_entity_id,
            'code' => $this->code,
            'kind' => $this->kind?->value ?? $this->kind,
            'next_number' => $this->next_number,
            'is_default' => (bool) $this->is_default,
            'issued_count' => $this->issuedCount(),
            'archived_at' => $this->datetime($this->archived_at),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }

    private function issuedCount(): int
    {
        if (isset($this->resource->invoices_count)) {
            return (int) $this->resource->invoices_count;
        }

        return (int) $this->resource->invoices()->count();
    }
}
