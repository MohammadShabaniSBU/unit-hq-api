<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\Advertising\AppendOnlyException;
use App\Support\Advertising\ConversionEventKey;
use Database\Factories\AdConversionEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Append-only conversion fact. Written inside the business transaction that produced it.
 *
 * @property int $id
 * @property ConversionEventKey $event_key
 * @property string $dedupe_id
 * @property int $contact_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $site_id
 * @property string|null $value
 * @property string|null $currency
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class AdConversionEvent extends Model
{
    /** @use HasFactory<AdConversionEventFactory> */
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_key',
        'dedupe_id',
        'contact_id',
        'subject_type',
        'subject_id',
        'site_id',
        'value',
        'currency',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_key' => ConversionEventKey::class,
            'value' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function appendOnlyMarker(): string
    {
        return 'ad_conversion_event';
    }

    protected function assertAppendOnlyUpdate(): void
    {
        if ($this->isDirty()) {
            throw AppendOnlyException::immutable(self::appendOnlyMarker());
        }
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<AdConversionDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AdConversionDelivery::class);
    }
}
