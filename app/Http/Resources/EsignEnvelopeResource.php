<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class EsignEnvelopeResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $document = $this->whenLoaded('contractDocument');
        $sha = $document ? (string) $document->sha256 : null;

        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'contract_document_id' => $this->contract_document_id,
            'document_sha256' => $sha,
            'document_sha256_prefix' => $sha !== null ? substr($sha, 0, 8) : null,
            'esign_provider_account_id' => $this->esign_provider_account_id,
            'provider_envelope_ref' => $this->provider_envelope_ref,
            'signer_name' => $this->signer_name,
            'signer_email' => $this->signer_email,
            'status' => $this->status?->value ?? $this->status,
            'decline_reason' => $this->decline_reason,
            'expires_at' => $this->datetime($this->expires_at),
            'sent_at' => $this->datetime($this->sent_at),
            'viewed_at' => $this->datetime($this->viewed_at),
            'signed_at' => $this->datetime($this->signed_at),
            'signed_pdf_sha256' => $this->signed_pdf_sha256,
            'has_signed_pdf' => $this->signed_pdf_path !== null,
            'has_certificate' => $this->certificate_path !== null,
            'completion_pending' => (bool) $this->completion_pending,
            'post_cancellation' => (bool) $this->post_cancellation,
            'created_by' => $this->created_by,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
