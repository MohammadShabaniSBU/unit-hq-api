<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Normalized door open/deny event from a provider webhook (S15-01).
 * Unmapped points and unresolved contacts are stored with NULL FKs — never dropped.
 *
 * @property int                   $id
 * @property int|null              $access_point_id
 * @property int|null              $contact_id
 * @property int|null              $access_grant_id
 * @property AccessEventType       $event_type
 * @property Carbon                $occurred_at
 * @property string|null           $provider_credential_ref
 * @property string|null           $provider_point_id
 * @property array                 $raw
 * @property Carbon                $created_at
 *
 * @property-read AccessPoint|null  $accessPoint
 * @property-read Contact|null      $contact
 * @property-read AccessGrant|null  $accessGrant
 */
class AccessEvent extends Model
{
    use HasFactory;
    use \App\Support\Auth\Concerns\VisibleToEmployee;

    public $timestamps = false;

    protected $fillable = [
        'access_point_id',
        'contact_id',
        'access_grant_id',
        'event_type',
        'occurred_at',
        'provider_credential_ref',
        'provider_point_id',
        'raw',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AccessEventType::class,
            'occurred_at' => 'datetime',
            'raw' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AccessPoint, AccessEvent> */
    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }

    /** @return BelongsTo<Contact, AccessEvent> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<AccessGrant, AccessEvent> */
    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class);
    }

    /**
     * Attention counters for settings / future chips (S15-04).
     *
     * @return array{unmapped_points_count: int, unresolved_contacts_count: int}
     */
    public static function attentionCounts(): array
    {
        return [
            'unmapped_points_count' => (int) static::query()
                ->whereNull('access_point_id')
                ->whereNotNull('provider_point_id')
                ->distinct()
                ->count('provider_point_id'),
            'unresolved_contacts_count' => (int) static::query()
                ->whereNull('contact_id')
                ->count(),
        ];
    }
}
