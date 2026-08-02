<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContractDocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable rendered contract PDF snapshot (legal artifact).
 *
 * @property int                     $id
 * @property int                     $contract_id
 * @property int                     $template_family_id
 * @property int                     $template_variant_id
 * @property Carbon                  $rendered_at
 * @property string                  $pdf_path
 * @property string                  $sha256
 * @property ContractDocumentStatus  $status
 * @property int|null                $envelope_id
 * @property Carbon                  $created_at
 * @property Carbon                  $updated_at
 *
 * @property-read Contract           $contract
 * @property-read TemplateFamily     $templateFamily
 * @property-read TemplateVariant    $templateVariant
 */
class ContractDocument extends Model
{
    protected $fillable = [
        'contract_id',
        'template_family_id',
        'template_variant_id',
        'rendered_at',
        'pdf_path',
        'sha256',
        'status',
        'envelope_id',
    ];

    protected function casts(): array
    {
        return [
            'rendered_at' => 'datetime',
            'status' => ContractDocumentStatus::class,
        ];
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<TemplateFamily, $this> */
    public function templateFamily(): BelongsTo
    {
        return $this->belongsTo(TemplateFamily::class);
    }

    /** @return BelongsTo<TemplateVariant, $this> */
    public function templateVariant(): BelongsTo
    {
        return $this->belongsTo(TemplateVariant::class);
    }

    /** @return BelongsTo<EsignEnvelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(EsignEnvelope::class, 'envelope_id');
    }
}
