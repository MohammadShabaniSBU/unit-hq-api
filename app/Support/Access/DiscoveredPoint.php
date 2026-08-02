<?php

declare(strict_types=1);

namespace App\Support\Access;

final readonly class DiscoveredPoint
{
    public function __construct(
        public string $providerPointId,
        public string $label,
        public ?string $kindHint = null,
    ) {}

    /** @return array{provider_point_id: string, label: string, kind_hint: string|null} */
    public function toArray(): array
    {
        return [
            'provider_point_id' => $this->providerPointId,
            'label' => $this->label,
            'kind_hint' => $this->kindHint,
        ];
    }
}
