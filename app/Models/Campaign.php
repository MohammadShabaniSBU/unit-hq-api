<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Support\Auth\Permission;
use App\Support\Communications\Exceptions\CampaignImmutable;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One broadcast: campaign, newsletter, or announcement.
 *
 * Audience columns lock once status leaves draft and pending_approval.
 *
 * @property int $id
 * @property CampaignType $type
 * @property string $name
 * @property CampaignStatus $status
 * @property int|null $subscription_list_id
 * @property int|null $segment_id
 * @property list<int> $site_ids
 * @property array<string, mixed>|null $audience_snapshot
 * @property int|null $estimated_recipients
 * @property int|null $newsletter_issue_number
 * @property bool $ignore_send_window
 * @property bool $approval_required
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejected_reason
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property int|null $duplicated_from_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SubscriptionList|null $subscriptionList
 * @property-read Segment|null $segment
 * @property-read Employee|null $submitter
 * @property-read Employee|null $approver
 * @property-read Employee|null $canceller
 * @property-read Campaign|null $duplicatedFrom
 * @property-read Employee|null $creator
 * @property-read Collection<int, CampaignSend> $sends
 */
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /** @var list<string> */
    private const AUDIENCE_FIELDS = [
        'type',
        'subscription_list_id',
        'segment_id',
        'site_ids',
        'audience_snapshot',
        'ignore_send_window',
    ];

    protected $fillable = [
        'type',
        'name',
        'status',
        'subscription_list_id',
        'segment_id',
        'site_ids',
        'audience_snapshot',
        'estimated_recipients',
        'newsletter_issue_number',
        'ignore_send_window',
        'approval_required',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'cancelled_by',
        'cancelled_at',
        'duplicated_from_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => CampaignType::class,
            'status' => CampaignStatus::class,
            'site_ids' => 'array',
            'audience_snapshot' => 'array',
            'ignore_send_window' => 'boolean',
            'approval_required' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $campaign): void {
            if (! self::audienceLocked($campaign)) {
                return;
            }

            foreach (self::AUDIENCE_FIELDS as $field) {
                if ($campaign->isDirty($field)) {
                    throw CampaignImmutable::audience();
                }
            }
        });
    }

    /** @return BelongsTo<SubscriptionList, $this> */
    public function subscriptionList(): BelongsTo
    {
        return $this->belongsTo(SubscriptionList::class);
    }

    /** @return BelongsTo<Segment, $this> */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'submitted_by');
    }

    /** @return BelongsTo<Employee, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    /** @return BelongsTo<Employee, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cancelled_by');
    }

    /** @return BelongsTo<Campaign, $this> */
    public function duplicatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicated_from_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @return HasMany<CampaignSend, $this> */
    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }

    /**
     * Site-scoped viewers see a campaign when every site id is inside their
     * grant. An empty site list is company-wide and stays visible.
     *
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    public function scopeVisibleTo(Builder $query, Employee $employee, Permission $permission = Permission::CampaignView): Builder
    {
        $siteIds = $employee->siteIdsFor($permission);

        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        if ($siteIds === null) {
            return $query;
        }

        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
        $outside = DB::getDriverName() === 'pgsql'
            ? "SELECT 1 FROM jsonb_array_elements_text(campaigns.site_ids) AS site_elem(site_id) WHERE site_id::int NOT IN ({$placeholders})"
            : "SELECT 1 FROM json_each(campaigns.site_ids) WHERE CAST(json_each.value AS INTEGER) NOT IN ({$placeholders})";

        return $query->whereRaw("NOT EXISTS ({$outside})", $siteIds);
    }

    /**
     * @param  list<int>|null  $grantedSiteIds  null = company-wide
     */
    public function sitesWithin(?array $grantedSiteIds): bool
    {
        if ($grantedSiteIds === null) {
            return true;
        }

        if ($grantedSiteIds === []) {
            return false;
        }

        foreach (array_map(intval(...), $this->site_ids ?? []) as $siteId) {
            if (! in_array($siteId, $grantedSiteIds, true)) {
                return false;
            }
        }

        return true;
    }

    private static function audienceLocked(self $campaign): bool
    {
        $mutable = [
            CampaignStatus::Draft->value,
            CampaignStatus::PendingApproval->value,
        ];

        return ! in_array(self::statusValue($campaign->getOriginal('status')), $mutable, true);
    }

    private static function statusValue(mixed $status): string
    {
        return $status instanceof CampaignStatus ? $status->value : (string) $status;
    }
}
