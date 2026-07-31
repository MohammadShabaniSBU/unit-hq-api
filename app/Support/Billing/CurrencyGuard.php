<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Charge;
use App\Models\Contract;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * One-contract-one-currency assertions (invariant 35).
 * Static, no state — same tier as BillingMath.
 */
final class CurrencyGuard
{
    /**
     * Every item resolves to the same currency, or throw. Returns the agreed currency.
     *
     * @param  Collection<int, object|array<string, mixed>>  $items
     *                                                                 each must expose a `currency` string (model attribute or array key)
     *
     * @throws ValidationException
     */
    public static function assertItemsAgree(Collection $items): string
    {
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'currency' => [__('errors.currency.mixed_contract_items')],
            ]);
        }

        $currencies = $items
            ->map(fn (mixed $item): ?string => self::extractCurrency($item))
            ->filter(fn (?string $c): bool => $c !== null && $c !== '')
            ->map(fn (string $c): string => SupportedCurrencies::normalize($c))
            ->unique()
            ->values();

        if ($currencies->count() !== 1) {
            throw ValidationException::withMessages([
                'currency' => [__('errors.currency.mixed_contract_items')],
            ]);
        }

        return $currencies->first();
    }

    /**
     * A ledger row's currency matches its contract, or throw.
     *
     * @throws ValidationException
     */
    public static function assertMatchesContract(Contract $contract, string $currency): void
    {
        $normalized = SupportedCurrencies::normalize($currency);
        $contractCurrency = SupportedCurrencies::normalize((string) $contract->currency);

        if ($normalized !== $contractCurrency) {
            throw ValidationException::withMessages([
                'currency' => [__('errors.currency.ledger_mismatch')],
            ]);
        }
    }

    /**
     * A payment and a charge share a currency before an allocation is written.
     *
     * @throws ValidationException
     */
    public static function assertAllocatable(Charge $charge, Payment $payment): void
    {
        $chargeCurrency = SupportedCurrencies::normalize((string) $charge->currency);
        $paymentCurrency = SupportedCurrencies::normalize((string) $payment->currency);

        if ($chargeCurrency !== $paymentCurrency) {
            throw ValidationException::withMessages([
                'currency' => [__('errors.currency.allocation_mismatch')],
            ]);
        }
    }

    /**
     * Site prefill currency vs price currency on a rate junction.
     *
     * @throws ValidationException
     */
    public static function assertRateJunction(
        ?string $siteCurrency,
        string $priceCurrency,
        bool $allowMismatch = false,
    ): void {
        if ($siteCurrency === null || $siteCurrency === '') {
            return;
        }

        if ($allowMismatch) {
            return;
        }

        $site = SupportedCurrencies::normalize($siteCurrency);
        $price = SupportedCurrencies::normalize($priceCurrency);

        if ($site !== $price) {
            throw ValidationException::withMessages([
                'currency' => [__('errors.currency.rate_junction_mismatch')],
            ]);
        }
    }

    /** @param  object|array<string, mixed>  $item */
    private static function extractCurrency(mixed $item): ?string
    {
        if (is_array($item)) {
            $value = $item['currency'] ?? null;

            return is_string($value) ? $value : null;
        }

        if (is_object($item) && isset($item->currency)) {
            $value = $item->currency;

            return is_string($value) ? $value : (string) $value;
        }

        return null;
    }
}
