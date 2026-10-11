<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\AppendOnlyException;
use Database\Factories\MarketingTouchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only ad touch. contact_id may move from null to an id once.
 * Redaction may clear click_ids, landing_url, referrer_host, utm_term, and utm_content.
 *
 * @property int $id
 * @property int|null $contact_id
 * @property string|null $visitor_key
 * @property Carbon $occurred_at
 * @property int|null $site_id
 * @property AdPlatform|null $platform
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_term
 * @property string|null $utm_content
 * @property array<string, mixed> $click_ids
 * @property string|null $landing_url
 * @property string|null $referrer_host
 * @property int|null $signup_form_id
 * @property array<string, mixed> $consent_snapshot
 * @property Carbon $created_at
 */
class MarketingTouch extends Model
{
    /** @use HasFactory<MarketingTouchFactory> */
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    private const REDACTION_COLUMNS = [
        'click_ids',
        'landing_url',
        'referrer_host',
        'utm_term',
        'utm_content',
    ];

    protected $fillable = [
        'contact_id',
        'visitor_key',
        'occurred_at',
        'site_id',
        'platform',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'click_ids',
        'landing_url',
        'referrer_host',
        'signup_form_id',
        'consent_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'platform' => AdPlatform::class,
            'click_ids' => 'array',
            'consent_snapshot' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function appendOnlyMarker(): string
    {
        return 'marketing_touch';
    }

    protected function assertAppendOnlyUpdate(): void
    {
        foreach (array_keys($this->getDirty()) as $field) {
            if (in_array($field, self::REDACTION_COLUMNS, true)) {
                continue;
            }

            if ($field === 'contact_id' && $this->getOriginal('contact_id') === null && $this->contact_id !== null) {
                continue;
            }

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

    /** @return BelongsTo<SignupForm, $this> */
    public function signupForm(): BelongsTo
    {
        return $this->belongsTo(SignupForm::class);
    }
}
