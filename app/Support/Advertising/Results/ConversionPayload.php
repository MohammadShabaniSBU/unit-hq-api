<?php

declare(strict_types=1);

namespace App\Support\Advertising\Results;

/**
 * Wire-ready conversion. S32-04 fills this; adapters only post it.
 * Google reads the click id, conversionDateTime, userIdentifiers, and consent.
 * Meta reads eventTime, metaUserData, and metaCustomData.
 */
final readonly class ConversionPayload
{
    /**
     * @param  list<array<string, string>>  $userIdentifiers
     * @param  array<string, mixed>  $metaUserData
     * @param  array<string, mixed>|null  $metaCustomData
     */
    public function __construct(
        public string $dedupeId,
        public string $externalRef,
        public ?string $gclid = null,
        public ?string $gbraid = null,
        public ?string $wbraid = null,
        public ?string $conversionDateTime = null,
        public ?string $value = null,
        public ?string $currency = null,
        public array $userIdentifiers = [],
        public ?string $adUserData = null,
        public ?string $adPersonalization = null,
        public ?int $eventTime = null,
        public array $metaUserData = [],
        public ?array $metaCustomData = null,
    ) {}
}
