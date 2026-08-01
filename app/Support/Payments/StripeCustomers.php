<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Enums\ContactChannelType;
use App\Models\Contact;
use App\Models\PaymentProviderAccount;
use App\Models\StripeCustomer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;

/**
 * Find-or-create a Stripe Customer per contact per provider account.
 * Race-safe on the unique (contact_id, payment_provider_account_id) index.
 */
final class StripeCustomers
{
    /**
     * @throws ApiErrorException
     * @throws \RuntimeException when the account has no secret key
     */
    public static function for(Contact $contact, PaymentProviderAccount $account): StripeCustomer
    {
        $existing = self::find($contact, $account);
        if ($existing !== null) {
            return $existing;
        }

        $secretKey = $account->secret_key;
        if ($secretKey === null || $secretKey === '') {
            throw new \RuntimeException('Payment provider account has no secret key.');
        }

        $stripe = app(StripeClient::class);
        $created = $stripe->createCustomer($secretKey, [
            'name' => self::displayName($contact),
            'email' => self::email($contact),
            'metadata' => [
                'contact_id' => (string) $contact->id,
                'payment_provider_account_id' => (string) $account->id,
            ],
        ]);

        // Another request may have won while we were talking to Stripe.
        $existing = self::find($contact, $account);
        if ($existing !== null) {
            return $existing;
        }

        try {
            // Nested transaction → savepoint so a unique violation does not
            // abort an outer DB transaction (e.g. RefreshDatabase / callers).
            return DB::transaction(function () use ($contact, $account, $created): StripeCustomer {
                $existing = self::find($contact, $account);
                if ($existing !== null) {
                    return $existing;
                }

                return StripeCustomer::query()->create([
                    'contact_id' => $contact->id,
                    'payment_provider_account_id' => $account->id,
                    'stripe_customer_id' => $created['id'],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return self::find($contact, $account)
                ?? throw new \RuntimeException('Stripe customer race recovery failed.');
        }
    }

    private static function find(Contact $contact, PaymentProviderAccount $account): ?StripeCustomer
    {
        return StripeCustomer::query()
            ->where('contact_id', $contact->id)
            ->where('payment_provider_account_id', $account->id)
            ->first();
    }

    private static function displayName(Contact $contact): ?string
    {
        $name = trim(implode(' ', array_filter([
            $contact->first_name,
            $contact->last_name,
        ])));

        if ($name !== '') {
            return $name;
        }

        return filled($contact->company) ? (string) $contact->company : null;
    }

    private static function email(Contact $contact): ?string
    {
        $contact->loadMissing('channels');

        $primaryEmail = $contact->channels
            ->first(fn ($channel): bool => $channel->type === ContactChannelType::Email && $channel->is_primary);

        if ($primaryEmail !== null && filled($primaryEmail->value)) {
            return (string) $primaryEmail->value;
        }

        $anyEmail = $contact->channels
            ->first(fn ($channel): bool => $channel->type === ContactChannelType::Email && filled($channel->value));

        if ($anyEmail !== null) {
            return (string) $anyEmail->value;
        }

        return filled($contact->email) ? (string) $contact->email : null;
    }
}
