<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EsignEnvelopeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Provider envelope for a contract document (S14-03).
 * Signed PDF + certificate paths are write-once legal artifacts.
 *
 * @property int                   $id
 * @property int                   $contract_id
 * @property int                   $contract_document_id
 * @property int                   $esign_provider_account_id
 * @property string                $provider_envelope_ref
 * @property string                $signer_name
 * @property string                $signer_email
 * @property EsignEnvelopeStatus   $status
 * @property string|null           $decline_reason
 * @property Carbon                $expires_at
 * @property Carbon                $sent_at
 * @property Carbon|null           $viewed_at
 * @property Carbon|null           $signed_at
 * @property string|null           $signed_pdf_path
 * @property string|null           $signed_pdf_sha256
 * @property string|null           $certificate_path
 * @property bool                  $completion_pending
 * @property bool                  $post_cancellation
 * @property int|null              $created_by
 * @property Carbon                $created_at
 * @property Carbon                $updated_at
 *
 * @property-read Contract              $contract
 * @property-read ContractDocument      $contractDocument
 * @property-read EsignProviderAccount  $esignProviderAccount
 * @property-read Employee|null         $createdBy
 */
class EsignEnvelope extends Model
{
    /** @var list<string> */
    private const IMMUTABLE_ONCE_SET = [
        'signed_pdf_path',
        'signed_pdf_sha256',
        'certificate_path',
    ];

    protected $fillable = [
        'contract_id',
        'contract_document_id',
        'esign_provider_account_id',
        'provider_envelope_ref',
        'signer_name',
        'signer_email',
        'status',
        'decline_reason',
        'expires_at',
        'sent_at',
        'viewed_at',
        'signed_at',
        'signed_pdf_path',
        'signed_pdf_sha256',
        'certificate_path',
        'completion_pending',
        'post_cancellation',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => EsignEnvelopeStatus::class,
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'completion_pending' => 'boolean',
            'post_cancellation' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (EsignEnvelope $envelope): void {
            foreach (self::IMMUTABLE_ONCE_SET as $attr) {
                $original = $envelope->getOriginal($attr);
                if ($original !== null && $original !== '' && $envelope->isDirty($attr)) {
                    throw new RuntimeException(
                        "EsignEnvelope.{$attr} is immutable once set."
                    );
                }
            }
        });
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<ContractDocument, $this> */
    public function contractDocument(): BelongsTo
    {
        return $this->belongsTo(ContractDocument::class);
    }

    /** @return BelongsTo<EsignProviderAccount, $this> */
    public function esignProviderAccount(): BelongsTo
    {
        return $this->belongsTo(EsignProviderAccount::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function isLive(): bool
    {
        $status = $this->status instanceof EsignEnvelopeStatus
            ? $this->status
            : EsignEnvelopeStatus::from((string) $this->status);

        return $status->isLive();
    }
}
