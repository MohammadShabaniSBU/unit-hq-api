<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FiscalRegime;
use App\Enums\TaxIdType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Fiscal issuer of invoices and holder of payment credentials.
 *
 * Archive-only — never hard-deleted. No global scope (invariant 34): this is a
 * fiscal domain concept, never a tenancy boundary.
 *
 * @property int              $id
 * @property string           $legal_name
 * @property string|null      $trading_name
 * @property string           $tax_id
 * @property TaxIdType        $tax_id_type
 * @property string|null      $vat_number
 * @property string           $country_code
 * @property string           $address_line1
 * @property string|null      $address_line2
 * @property string           $city
 * @property string           $postal_code
 * @property FiscalRegime     $fiscal_regime
 * @property string|null      $sepa_creditor_id
 * @property Carbon|null      $archived_at
 * @property Carbon           $created_at
 * @property Carbon           $updated_at
 *
 * @property-read Collection<int, Site>                    $sites
 * @property-read Collection<int, InvoiceSeries>           $invoiceSeries
 * @property-read Collection<int, Invoice>                 $invoices
 * @property-read Collection<int, PaymentProviderAccount>  $paymentProviderAccounts
 * @property-read int|null                                 $sites_count
 */
class LegalEntity extends Model
{
    use HasFactory;

    protected $fillable = [
        'legal_name',
        'trading_name',
        'tax_id',
        'tax_id_type',
        'vat_number',
        'country_code',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'fiscal_regime',
        'sepa_creditor_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'tax_id_type' => TaxIdType::class,
            'fiscal_regime' => FiscalRegime::class,
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<LegalEntity> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<LegalEntity> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<InvoiceSeries, $this> */
    public function invoiceSeries(): HasMany
    {
        return $this->hasMany(InvoiceSeries::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<PaymentProviderAccount, $this> */
    public function paymentProviderAccounts(): HasMany
    {
        return $this->hasMany(PaymentProviderAccount::class);
    }

    /** True once any fiscal invoice has been issued under this entity. */
    public function hasIssuedInvoices(): bool
    {
        return $this->invoices()->exists();
    }

    public function activeSitesCount(): int
    {
        return $this->sites()->whereNull('archived_at')->count();
    }
}
