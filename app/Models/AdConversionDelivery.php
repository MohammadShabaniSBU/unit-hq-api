<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Advertising\AdDeliverySkipReason;
use App\Support\Advertising\AdDeliveryStatus;
use App\Support\Advertising\AppendOnlyException;
use Database\Factories\AdConversionDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One send attempt for one ad account and one conversion event.
 * Status moves pending to claimed to sent, skipped, or failed.
 * failed may return to pending. After sent, the event, account, and
 * identifier names stay fixed.
 *
 * @property int $id
 * @property int $ad_conversion_event_id
 * @property int $ad_platform_account_id
 * @property int|null $marketing_touch_id
 * @property AdDeliveryStatus $status
 * @property AdDeliverySkipReason|null $skip_reason
 * @property array<int, string> $identifiers_used
 * @property int $attempts
 * @property Carbon|null $claimed_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 * @property array<string, mixed>|null $provider_response
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdConversionDelivery extends Model
{
    /** @use HasFactory<AdConversionDeliveryFactory> */
    use HasFactory;

    /** @var list<string> */
    private const FROZEN_AFTER_SENT = [
        'ad_conversion_event_id',
        'ad_platform_account_id',
        'identifiers_used',
    ];

    protected $fillable = [
        'ad_conversion_event_id',
        'ad_platform_account_id',
        'marketing_touch_id',
        'status',
        'skip_reason',
        'identifiers_used',
        'attempts',
        'claimed_at',
        'processed_at',
        'error',
        'provider_response',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdDeliveryStatus::class,
            'skip_reason' => AdDeliverySkipReason::class,
            'identifiers_used' => 'array',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'processed_at' => 'datetime',
            'provider_response' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $delivery): void {
            $delivery->assertStatusMove();
            $delivery->assertFrozenAfterSent();
        });
    }

    private function assertStatusMove(): void
    {
        if (! $this->isDirty('status')) {
            return;
        }

        $from = $this->originalStatus();
        $to = $this->status;

        if (! $from->canMoveTo($to)) {
            throw AppendOnlyException::status();
        }
    }

    private function assertFrozenAfterSent(): void
    {
        if ($this->originalStatus() !== AdDeliveryStatus::Sent) {
            return;
        }

        foreach (self::FROZEN_AFTER_SENT as $field) {
            if ($this->isDirty($field)) {
                throw AppendOnlyException::immutable('ad_conversion_delivery');
            }
        }
    }

    private function originalStatus(): AdDeliveryStatus
    {
        $status = $this->getOriginal('status');

        return $status instanceof AdDeliveryStatus
            ? $status
            : AdDeliveryStatus::from((string) $status);
    }

    /** @return BelongsTo<AdConversionEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(AdConversionEvent::class, 'ad_conversion_event_id');
    }

    /** @return BelongsTo<AdPlatformAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdPlatformAccount::class, 'ad_platform_account_id');
    }

    /** @return BelongsTo<MarketingTouch, $this> */
    public function marketingTouch(): BelongsTo
    {
        return $this->belongsTo(MarketingTouch::class, 'marketing_touch_id');
    }
}
