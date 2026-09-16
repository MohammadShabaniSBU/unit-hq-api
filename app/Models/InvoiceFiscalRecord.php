<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FiscalRegime;
use App\Enums\InvoiceFiscalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only fiscal submission record, one per invoice per regime.
 *
 * @property int $id
 * @property int $invoice_id
 * @property FiscalRegime $regime
 * @property array<string, mixed>|null $payload
 * @property string|null $hash
 * @property string|null $prev_hash
 * @property InvoiceFiscalStatus $status
 * @property Carbon|null $submitted_at
 */
class InvoiceFiscalRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'regime',
        'payload',
        'hash',
        'prev_hash',
        'status',
        'submitted_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'regime' => FiscalRegime::class,
            'payload' => 'array',
            'status' => InvoiceFiscalStatus::class,
            'submitted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
