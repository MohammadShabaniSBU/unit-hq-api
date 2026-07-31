<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Snapshot of a charge on a fiscal invoice. Append-only.
 *
 * @property int         $id
 * @property int         $invoice_id
 * @property int         $charge_id
 * @property string      $description
 * @property string|null $period_start Y-m-d
 * @property string|null $period_end   Y-m-d
 * @property string      $net_amount
 * @property string      $tax_rate_snapshot
 * @property string      $tax_amount
 * @property string      $gross_amount
 * @property Carbon      $created_at
 *
 * @property-read Invoice $invoice
 * @property-read Charge  $charge
 */
class InvoiceLine extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id',
        'charge_id',
        'description',
        'period_start',
        'period_end',
        'net_amount',
        'tax_rate_snapshot',
        'tax_amount',
        'gross_amount',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'net_amount' => 'decimal:2',
            'tax_rate_snapshot' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Charge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
