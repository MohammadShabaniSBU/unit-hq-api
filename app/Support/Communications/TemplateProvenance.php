<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Contact;
use App\Models\Site;
use App\Models\TemplateVariant;

/**
 * Version stamp written to messages.detail.template for a templated send.
 */
final readonly class TemplateProvenance
{
    public function __construct(
        public int $familyId,
        public int $versionId,
        public int $versionNumber,
        public int $variantId,
        public string $locale,
        public string $preferredLocale,
    ) {}

    public static function from(TemplateVariant $variant, Contact $contact, ?Site $site): self
    {
        $variant->loadMissing('version');

        return new self(
            familyId: (int) $variant->template_family_id,
            versionId: (int) $variant->template_version_id,
            versionNumber: (int) $variant->version->version_number,
            variantId: (int) $variant->id,
            locale: (string) $variant->locale,
            preferredLocale: TemplateResolver::preferredLocale($contact, $site),
        );
    }

    /**
     * @return array{
     *     family_id: int,
     *     version_id: int,
     *     version_number: int,
     *     variant_id: int,
     *     locale: string,
     *     preferred_locale: string
     * }
     */
    public function toArray(): array
    {
        return [
            'family_id' => $this->familyId,
            'version_id' => $this->versionId,
            'version_number' => $this->versionNumber,
            'variant_id' => $this->variantId,
            'locale' => $this->locale,
            'preferred_locale' => $this->preferredLocale,
        ];
    }
}
