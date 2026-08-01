<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Local map of a Contact to a Stripe Customer on a provider account.
 * Card data never lives here — only the Stripe customer id.
 *
 * @property int         $id
 * @property int         $contact_id
 * @property int         $payment_provider_account_id
 * @property string      $stripe_customer_id
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Contact                $contact
 * @property-read PaymentProviderAccount $paymentProviderAccount
 */
class StripeCustomer extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'payment_provider_account_id',
        'stripe_customer_id',
    ];

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
}
