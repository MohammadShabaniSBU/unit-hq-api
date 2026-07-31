<?php

namespace App\Settings;

readonly class LeasingSettings implements SettingsPayload
{
    public function __construct(
        public int $defaultOfferExpirationValue,
        public string $defaultOfferExpirationUnit,
        public int $defaultReservationExpirationValue,
        public string $defaultReservationExpirationUnit,
        public int $defaultNoticePeriodDays,
    ) {}

    public static function default(): static
    {
        return new self(
            defaultOfferExpirationValue: 7,
            defaultOfferExpirationUnit: 'days',
            defaultReservationExpirationValue: 3,
            defaultReservationExpirationUnit: 'days',
            defaultNoticePeriodDays: 14,
        );
    }

    public static function fromArray(array $data): static
    {
        return new self(
            defaultOfferExpirationValue: $data['default_offer_expiration_value'],
            defaultOfferExpirationUnit: $data['default_offer_expiration_unit'],
            defaultReservationExpirationValue: $data['default_reservation_expiration_value'],
            defaultReservationExpirationUnit: $data['default_reservation_expiration_unit'],
            defaultNoticePeriodDays: (int) ($data['default_notice_period_days'] ?? 14),
        );
    }

    public function toArray(): array
    {
        return [
            'default_offer_expiration_value'        => $this->defaultOfferExpirationValue,
            'default_offer_expiration_unit'         => $this->defaultOfferExpirationUnit,
            'default_reservation_expiration_value'  => $this->defaultReservationExpirationValue,
            'default_reservation_expiration_unit'   => $this->defaultReservationExpirationUnit,
            'default_notice_period_days'            => $this->defaultNoticePeriodDays,
        ];
    }

    public function with(
        ?int $defaultOfferExpirationValue = null,
        ?string $defaultOfferExpirationUnit = null,
        ?int $defaultReservationExpirationValue = null,
        ?string $defaultReservationExpirationUnit = null,
        ?int $defaultNoticePeriodDays = null,
    ): static {
        return new self(
            defaultOfferExpirationValue: $defaultOfferExpirationValue ?? $this->defaultOfferExpirationValue,
            defaultOfferExpirationUnit: $defaultOfferExpirationUnit ?? $this->defaultOfferExpirationUnit,
            defaultReservationExpirationValue: $defaultReservationExpirationValue ?? $this->defaultReservationExpirationValue,
            defaultReservationExpirationUnit: $defaultReservationExpirationUnit ?? $this->defaultReservationExpirationUnit,
            defaultNoticePeriodDays: $defaultNoticePeriodDays ?? $this->defaultNoticePeriodDays,
        );
    }
}
