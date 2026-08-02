<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Provider-synced WhatsApp template registry (approval substrate).
 * Manager UI / sync land in S13-03; send path reads live status here.
 *
 * @property int                       $id
 * @property string                    $name
 * @property string                    $language
 * @property string                    $category
 * @property string|null               $header_text
 * @property string                    $body
 * @property string|null               $footer_text
 * @property array<int, mixed>|null    $buttons
 * @property array<int, mixed>         $variables
 * @property string                    $status
 * @property string|null               $rejection_reason
 * @property string|null               $provider_template_id
 * @property Carbon|null               $submitted_at
 * @property Carbon|null               $decided_at
 * @property int                       $communication_account_id
 * @property int|null                  $created_by
 * @property Carbon                    $created_at
 * @property Carbon                    $updated_at
 *
 * @property-read CommunicationAccount $communicationAccount
 */
class WhatsappTemplate extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'language',
        'category',
        'header_text',
        'body',
        'footer_text',
        'buttons',
        'variables',
        'status',
        'rejection_reason',
        'provider_template_id',
        'submitted_at',
        'decided_at',
        'communication_account_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'buttons' => 'array',
            'variables' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function communicationAccount(): BelongsTo
    {
        return $this->belongsTo(CommunicationAccount::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
