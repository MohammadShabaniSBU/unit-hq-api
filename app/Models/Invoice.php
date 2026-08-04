<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Numbered fiscal document. Immutable once issued — corrections are rectificatives.
 * Snapshots carry every field needed to re-render years later.
 *
 * @property int               $id
 * @property int               $legal_entity_id
 * @property int               $invoice_series_id
 * @property int               $number
 * @property string            $full_number
 * @property InvoiceKind       $kind
 * @property InvoiceStatus     $status
 * @property string|null       $issue_date Y-m-d
 * @property int|null          $contract_id
 * @property int               $contact_id
 * @property int|null          $rectifies_invoice_id
 * @property string|null       $rectification_reason
 * @property string            $issuer_name
 * @property string            $issuer_tax_id
 * @property array             $issuer_address
 * @property string|null       $buyer_name
 * @property string|null       $buyer_tax_id
 * @property array|null        $buyer_address
 * @property string            $currency
 * @property string            $net_total
 * @property string            $tax_total
 * @property string            $gross_total
 * @property string|null       $verifactu_hash
 * @property string|null       $verifactu_prev_hash
 * @property Carbon|null       $verifactu_submitted_at
 * @property int|null          $created_by
 * @property Carbon            $created_at
 * @property Carbon            $updated_at
 *
 * @property-read LegalEntity              $legalEntity
 * @property-read InvoiceSeries            $invoiceSeries
 * @property-read Contract|null            $contract
 * @property-read Contact                  $contact
 * @property-read Invoice|null             $rectifiesInvoice
 * @property-read Collection<int, Invoice> $rectificatives
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, Charge>  $charges
 * @property-read Employee|null            $creator
 */
class Invoice extends Model
{
    use HasFactory;
    use \App\Support\Auth\Concerns\VisibleToEmployee;

    protected $fillable = [
        'legal_entity_id',
        'invoice_series_id',
        'number',
        'full_number',
        'kind',
        'status',
        'issue_date',
        'contract_id',
        'contact_id',
        'rectifies_invoice_id',
        'rectification_reason',
        'issuer_name',
        'issuer_tax_id',
        'issuer_address',
        'buyer_name',
        'buyer_tax_id',
        'buyer_address',
        'currency',
        'net_total',
        'tax_total',
        'gross_total',
        'verifactu_hash',
        'verifactu_prev_hash',
        'verifactu_submitted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'issuer_address' => 'array',
            'buyer_address' => 'array',
            'net_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'verifactu_submitted_at' => 'datetime',
            'number' => 'integer',
        ];
    }

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /** @return BelongsTo<InvoiceSeries, $this> */
    public function invoiceSeries(): BelongsTo
    {
        return $this->belongsTo(InvoiceSeries::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function rectifiesInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectifies_invoice_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function rectificatives(): HasMany
    {
        return $this->hasMany(self::class, 'rectifies_invoice_id');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<Charge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
