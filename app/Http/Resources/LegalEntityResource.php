<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class LegalEntityResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'trading_name' => $this->trading_name,
            'tax_id' => $this->tax_id,
            'tax_id_type' => $this->tax_id_type?->value ?? $this->tax_id_type,
            'vat_number' => $this->vat_number,
            'country_code' => $this->country_code,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'fiscal_regime' => $this->fiscal_regime?->value ?? $this->fiscal_regime,
            'sepa_creditor_id' => $this->sepa_creditor_id,
            'sites_count' => $this->whenCounted('sites'),
            'archived_at' => $this->datetime($this->archived_at),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
