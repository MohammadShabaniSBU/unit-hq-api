<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContractNoticeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only notice audit trail (rate changes, delinquency, move-out, etc.).
 * Never updated or deleted — supersede with a new row.
 *
 * @property int                     $id
 * @property int                     $contract_id
 * @property ContractNoticeType      $notice_type
 * @property string|null             $effective_date
 * @property string|null             $required_by
 * @property Carbon|null             $sent_at
 * @property string|null             $sent_channel
 * @property string|null             $sent_to
 * @property string|null             $document_ref
 * @property string|null             $short_notice_reason
 * @property int|null                $contract_item_id
 * @property int|null                $created_by
 * @property Carbon                  $created_at
 * @property Carbon                  $updated_at
 *
 * @property-read Contract           $contract
 * @property-read ContractItem|null  $contractItem
 * @property-read Employee|null      $creator
 */
class ContractNotice extends Model
{
    use HasFactory;
    use \App\Support\Auth\Concerns\VisibleToEmployee;

    protected $fillable = [
        'contract_id',
        'notice_type',
        'effective_date',
        'required_by',
        'sent_at',
        'sent_channel',
        'sent_to',
        'document_ref',
        'short_notice_reason',
        'contract_item_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'notice_type' => ContractNoticeType::class,
            'effective_date' => 'date',
            'required_by' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<ContractItem, $this> */
    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
