<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentInstrumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Local mirror of a saved payment instrument (display + reference only).
 * Card data never touches the application — rows are created from webhooks.
 * Distinct from App\Enums\PaymentMethod (ledger rail).
 *
 * @property int                        $id
 * @property int                        $contact_id
 * @property PaymentInstrumentType      $type
 * @property int|null                   $sepa_mandate_id
 * @property string|null                $stripe_pm_id
 * @property int|null                   $payment_provider_account_id
 * @property string                     $display_label
 * @property bool                       $is_default
 * @property Carbon|null                $archived_at
 * @property Carbon                     $created_at
 * @property Carbon                     $updated_at
 *
 * @property-read Contact                     $contact
 * @property-read PaymentProviderAccount|null $paymentProviderAccount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Contract> $contracts
 */
class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'type',
        'sepa_mandate_id',
        'stripe_pm_id',
        'payment_provider_account_id',
        'display_label',
        'is_default',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentInstrumentType::class,
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<PaymentMethod>  $query
     * @return Builder<PaymentMethod>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<PaymentProviderAccount, $this> */
    public function paymentProviderAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderAccount::class);
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
