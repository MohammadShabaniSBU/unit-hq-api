<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentAction;
use App\Support\Marketing\ConsentSource;
use Database\Factories\ConsentEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only consent ledger row. Current state is the latest event, never a column.
 *
 * @property int $id
 * @property int $contact_id
 * @property Channel $channel
 * @property int $subscription_list_id
 * @property ConsentAction $action
 * @property ConsentSource $source
 * @property int|null $consent_text_id
 * @property array<string, mixed>|null $evidence
 * @property string|null $causer_type
 * @property int|null $causer_id
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class ConsentEvent extends Model
{
    /** @use HasFactory<ConsentEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'contact_id',
        'channel',
        'subscription_list_id',
        'action',
        'source',
        'consent_text_id',
        'evidence',
        'causer_type',
        'causer_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'action' => ConsentAction::class,
            'source' => ConsentSource::class,
            'evidence' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<SubscriptionList, $this> */
    public function subscriptionList(): BelongsTo
    {
        return $this->belongsTo(SubscriptionList::class);
    }

    /** @return BelongsTo<ConsentText, $this> */
    public function consentText(): BelongsTo
    {
        return $this->belongsTo(ConsentText::class);
    }

    /** @return MorphTo<Model, $this> */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }
}
