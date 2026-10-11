<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdSpendDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * External restated daily spend. cost is NUMERIC(12,2), not a ledger amount.
 *
 * @property int $id
 * @property int $ad_campaign_id
 * @property Carbon $spend_date
 * @property int $impressions
 * @property int $clicks
 * @property string $cost
 * @property string $currency
 * @property Carbon $synced_at
 */
class AdSpendDaily extends Model
{
    /** @use HasFactory<AdSpendDailyFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'ad_spend_daily';

    protected $fillable = [
        'ad_campaign_id',
        'spend_date',
        'impressions',
        'clicks',
        'cost',
        'currency',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'spend_date' => 'date',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'cost' => 'decimal:2',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AdCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }
}
