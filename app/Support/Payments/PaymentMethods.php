<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Enums\PaymentInstrumentType;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;

/**
 * Local payment-method instrument helpers. Rows are created from verified
 * webhooks only (invariant 11 spirit applied to instruments).
 */
final class PaymentMethods
{
    /**
     * Create (or return existing) local row from a succeeded SetupIntent payload.
     *
     * @param  array<string, mixed>  $setupIntent  Stripe SetupIntent object as array
     */
    public static function recordFromSetupIntent(
        PaymentProviderAccount $account,
        array $setupIntent,
    ): ?PaymentMethod {
        $stripePmId = self::stringOrNull($setupIntent['payment_method'] ?? null);
        if ($stripePmId === null) {
            return null;
        }

        $existing = PaymentMethod::query()
            ->where('stripe_pm_id', $stripePmId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $metadata = is_array($setupIntent['metadata'] ?? null) ? $setupIntent['metadata'] : [];
        $contactId = isset($metadata['contact_id']) ? (int) $metadata['contact_id'] : 0;
        if ($contactId < 1) {
            return null;
        }

        $accountIdFromMeta = isset($metadata['payment_provider_account_id'])
            ? (int) $metadata['payment_provider_account_id']
            : $account->id;

        $details = self::retrieveCardDetails($account, $stripePmId);
        $label = self::displayLabel($details['brand'], $details['last4']);

        $isFirst = ! PaymentMethod::query()
            ->where('contact_id', $contactId)
            ->where('payment_provider_account_id', $accountIdFromMeta)
            ->active()
            ->exists();

        try {
            return DB::transaction(function () use ($contactId, $accountIdFromMeta, $stripePmId, $label, $isFirst): PaymentMethod {
                return PaymentMethod::query()->create([
                    'contact_id' => $contactId,
                    'type' => PaymentInstrumentType::StripeCard,
                    'sepa_mandate_id' => null,
                    'stripe_pm_id' => $stripePmId,
                    'payment_provider_account_id' => $accountIdFromMeta,
                    'display_label' => $label,
                    'is_default' => $isFirst,
                    'archived_at' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return PaymentMethod::query()
                ->where('stripe_pm_id', $stripePmId)
                ->firstOrFail();
        }
    }

    public static function setDefault(PaymentMethod $method): PaymentMethod
    {
        if ($method->isArchived()) {
            throw new \InvalidArgumentException('Cannot default an archived payment method.');
        }

        return DB::transaction(function () use ($method): PaymentMethod {
            PaymentMethod::query()
                ->where('contact_id', $method->contact_id)
                ->where('payment_provider_account_id', $method->payment_provider_account_id)
                ->where('id', '!=', $method->id)
                ->where('is_default', true)
                ->whereNull('archived_at')
                ->update(['is_default' => false]);

            $method->is_default = true;
            $method->save();

            return $method->fresh() ?? $method;
        });
    }

    /**
     * Archive locally after best-effort Stripe detach.
     *
     * @throws \RuntimeException when referenced by a contract (autopay guard)
     */
    public static function archive(PaymentMethod $method): PaymentMethod
    {
        if ($method->isArchived()) {
            return $method;
        }

        if ($method->contracts()->exists()) {
            throw new \RuntimeException(
                'This payment method is referenced by a contract. Change the contract payment method before removing it.'
            );
        }

        $account = $method->paymentProviderAccount;
        if (
            $account !== null
            && filled($method->stripe_pm_id)
            && filled($account->secret_key)
        ) {
            try {
                app(StripeClient::class)->detachPaymentMethod(
                    (string) $account->secret_key,
                    (string) $method->stripe_pm_id,
                );
            } catch (ApiErrorException) {
                // Best-effort — local archive still proceeds.
            }
        }

        $method->archived_at = now();
        $method->is_default = false;
        $method->save();

        return $method;
    }

    public static function displayLabel(?string $brand, ?string $last4): string
    {
        $brandLabel = $brand !== null && $brand !== ''
            ? ucfirst(strtolower($brand))
            : 'Card';

        $suffix = $last4 !== null && $last4 !== ''
            ? '···'.$last4
            : '····';

        return "{$brandLabel} {$suffix}";
    }

    /**
     * @return array{brand: string|null, last4: string|null}
     */
    private static function retrieveCardDetails(PaymentProviderAccount $account, string $stripePmId): array
    {
        $secretKey = $account->secret_key;
        if ($secretKey === null || $secretKey === '') {
            return ['brand' => null, 'last4' => null];
        }

        try {
            $pm = app(StripeClient::class)->retrievePaymentMethod($secretKey, $stripePmId);
        } catch (ApiErrorException) {
            return ['brand' => null, 'last4' => null];
        }

        return [
            'brand' => $pm['card']['brand'] ?? null,
            'last4' => $pm['card']['last4'] ?? null,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && isset($value['id']) && is_string($value['id']) && $value['id'] !== '') {
            return $value['id'];
        }

        return null;
    }
}
