<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Advertising\ConversionEventKey;
use App\Support\Advertising\ConversionValueRule;
use Database\Factories\AdConversionMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Which platform object receives one conversion event key for one ad account.
 *
 * @property int $id
 * @property int $ad_platform_account_id
 * @property ConversionEventKey $event_key
 * @property string $external_ref
 * @property ConversionValueRule $value_rule
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdConversionMapping extends Model
{
    /** @use HasFactory<AdConversionMappingFactory> */
    use HasFactory;

    protected $fillable = [
        'ad_platform_account_id',
        'event_key',
        'external_ref',
        'value_rule',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'event_key' => ConversionEventKey::class,
            'value_rule' => ConversionValueRule::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<AdPlatformAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdPlatformAccount::class, 'ad_platform_account_id');
    }
}
