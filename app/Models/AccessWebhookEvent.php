<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw access provider webhook event stored for idempotent reconciliation.
 * Unique on (access_provider_account_id, provider_event_id).
 *
 * @property int         $id
 * @property int         $access_provider_account_id
 * @property string      $provider_event_id
 * @property array       $payload
 * @property string      $processing_status pending|processed|failed
 * @property Carbon      $received_at
 * @property Carbon|null $processed_at
 *
 * @property-read AccessProviderAccount $accessProviderAccount
 */
class AccessWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'access_provider_account_id',
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

    /** @return BelongsTo<AccessProviderAccount, $this> */
    public function accessProviderAccount(): BelongsTo
    {
        return $this->belongsTo(AccessProviderAccount::class);
    }
}
