<?php

namespace App\Settings;

readonly class BillingSettings implements SettingsPayload
{
    public function __construct(
        public string $defaultCurrency,
        public string $defaultBillingInterval,
        public int $defaultBillingIntervalCount,
        public string $billingAnchorModel,
        public int $billingAnchorDay,
        public string $prorationMethod,
        public string $defaultDepositAmount,
        public string $moveOutSettlement = 'none',
        public int $turnoverHoldDays = 0,
        public string $transferBilling = 'prorate_immediately',
        public int $billingHorizonDays = 0,
    ) {}

    public static function default(): static
    {
        return new self(
            defaultCurrency: '',
            defaultBillingInterval: 'month',
            defaultBillingIntervalCount: 1,
            billingAnchorModel: 'anniversary',
            billingAnchorDay: 1,
            prorationMethod: 'daily',
            defaultDepositAmount: '0.00',
            moveOutSettlement: 'none',
            turnoverHoldDays: 0,
            transferBilling: 'prorate_immediately',
            billingHorizonDays: 0,
        );
    }

    public static function fromArray(array $data): static
    {
        [$interval, $intervalCount] = self::resolveLegacyInterval($data);

        return new self(
            defaultCurrency: $data['default_currency'] ?? '',
            defaultBillingInterval: $data['default_billing_interval'] ?? $interval,
            defaultBillingIntervalCount: (int) ($data['default_billing_interval_count'] ?? $intervalCount),
            billingAnchorModel: $data['billing_anchor_model'] ?? 'anniversary',
            billingAnchorDay: (int) ($data['billing_anchor_day'] ?? 1),
            prorationMethod: $data['proration_method'] ?? 'daily',
            defaultDepositAmount: $data['default_deposit_amount'] ?? '0.00',
            moveOutSettlement: $data['move_out_settlement'] ?? 'none',
            turnoverHoldDays: (int) ($data['turnover_hold_days'] ?? 0),
            transferBilling: $data['transfer_billing'] ?? 'prorate_immediately',
            billingHorizonDays: (int) ($data['billing_horizon_days'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'default_currency'               => $this->defaultCurrency,
            'default_billing_interval'       => $this->defaultBillingInterval,
            'default_billing_interval_count' => $this->defaultBillingIntervalCount,
            'billing_anchor_model'           => $this->billingAnchorModel,
            'billing_anchor_day'             => $this->billingAnchorDay,
            'proration_method'               => $this->prorationMethod,
            'default_deposit_amount'         => $this->defaultDepositAmount,
            'move_out_settlement'            => $this->moveOutSettlement,
            'turnover_hold_days'             => $this->turnoverHoldDays,
            'transfer_billing'               => $this->transferBilling,
            'billing_horizon_days'           => $this->billingHorizonDays,
        ];
    }

    public function with(
        ?string $defaultCurrency = null,
        ?string $defaultBillingInterval = null,
        ?int $defaultBillingIntervalCount = null,
        ?string $billingAnchorModel = null,
        ?int $billingAnchorDay = null,
        ?string $prorationMethod = null,
        ?string $defaultDepositAmount = null,
        ?string $moveOutSettlement = null,
        ?int $turnoverHoldDays = null,
        ?string $transferBilling = null,
        ?int $billingHorizonDays = null,
    ): static {
        return new self(
            defaultCurrency: $defaultCurrency ?? $this->defaultCurrency,
            defaultBillingInterval: $defaultBillingInterval ?? $this->defaultBillingInterval,
            defaultBillingIntervalCount: $defaultBillingIntervalCount ?? $this->defaultBillingIntervalCount,
            billingAnchorModel: $billingAnchorModel ?? $this->billingAnchorModel,
            billingAnchorDay: $billingAnchorDay ?? $this->billingAnchorDay,
            prorationMethod: $prorationMethod ?? $this->prorationMethod,
            defaultDepositAmount: $defaultDepositAmount ?? $this->defaultDepositAmount,
            moveOutSettlement: $moveOutSettlement ?? $this->moveOutSettlement,
            turnoverHoldDays: $turnoverHoldDays ?? $this->turnoverHoldDays,
            transferBilling: $transferBilling ?? $this->transferBilling,
            billingHorizonDays: $billingHorizonDays ?? $this->billingHorizonDays,
        );
    }

    /**
     * Legacy payloads stored `default_billing_period` (monthly|weekly|annual).
     * Map it to interval + count so old stored settings keep working.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int}
     */
    private static function resolveLegacyInterval(array $data): array
    {
        return match ($data['default_billing_period'] ?? null) {
            'weekly' => ['week', 1],
            'annual' => ['month', 12],
            'monthly' => ['month', 1],
            default => ['month', 1],
        };
    }
}
