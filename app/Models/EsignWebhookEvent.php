<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw e-sign provider webhook event stored for idempotent reconciliation.
 * Unique on (esign_provider_account_id, provider_event_id).
 *
 * @property int         $id
 * @property int         $esign_provider_account_id
 * @property string      $provider_event_id
 * @property array       $payload
 * @property string      $processing_status pending|processed|failed
 * @property Carbon      $received_at
 * @property Carbon|null $processed_at
 *
 * @property-read EsignProviderAccount $esignProviderAccount
 */
class EsignWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'esign_provider_account_id',
        'provider_event_id',
        'payload',
        'processing_status',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EsignProviderAccount, $this> */
    public function esignProviderAccount(): BelongsTo
    {
        return $this->belongsTo(EsignProviderAccount::class);
    }
}
