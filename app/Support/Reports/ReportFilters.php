<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared filter contract for every report. Validated once at the HTTP edge
 * (or via {@see self::validateAndMake()} in tests).
 */
final readonly class ReportFilters
{
    /**
     * @param  list<int>|null  $siteIds
     * @param  string|null  $platform  null means google_ads and meta
     * @param  int  $attributionWindow  30, 60, or 90 days
     * @param  string  $acquisition  all, paid, or organic (funnel only)
     */
    public function __construct(
        public ?array $siteIds = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $asOf = null,
        public string $locale = 'en',
        public ?string $platform = null,
        public int $attributionWindow = 90,
        public string $acquisition = 'all',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'site_ids' => ['sometimes', 'nullable', 'array'],
            'site_ids.*' => ['integer', 'distinct', 'exists:sites,id'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'as_of' => ['sometimes', 'nullable', 'date'],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['en', 'es'])],
            'format' => ['sometimes', 'nullable', 'string', Rule::in(['json', 'csv'])],
            'platform' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'google_ads', 'meta'])],
            'attribution_window' => ['sometimes', 'nullable', Rule::in([30, 60, 90, '30', '60', '90'])],
            'acquisition' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'paid', 'organic'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validateAndMake(array $input): self
    {
        $validated = Validator::make($input, self::rules())->validate();

        return self::fromValidated($validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $siteIds = null;
        if (array_key_exists('site_ids', $validated) && is_array($validated['site_ids'])) {
            $siteIds = array_values(array_map(
                static fn (mixed $id): int => (int) $id,
                $validated['site_ids'],
            ));
            if ($siteIds === []) {
                $siteIds = null;
            }
        }

        $locale = isset($validated['locale']) && is_string($validated['locale']) && $validated['locale'] !== ''
            ? $validated['locale']
            : 'en';

        $platform = isset($validated['platform']) && is_string($validated['platform'])
            ? $validated['platform']
            : null;
        if ($platform === 'all' || $platform === '') {
            $platform = null;
        }

        $window = 90;
        if (isset($validated['attribution_window']) && $validated['attribution_window'] !== '' && $validated['attribution_window'] !== null) {
            $window = (int) $validated['attribution_window'];
        }

        $acquisition = isset($validated['acquisition']) && is_string($validated['acquisition']) && $validated['acquisition'] !== ''
            ? $validated['acquisition']
            : 'all';

        return new self(
            siteIds: $siteIds,
            from: self::nullableDate($validated['from'] ?? null),
            to: self::nullableDate($validated['to'] ?? null),
            asOf: self::nullableDate($validated['as_of'] ?? null),
            locale: $locale,
            platform: $platform,
            attributionWindow: $window,
            acquisition: $acquisition,
        );
    }

    /**
     * Intersect client site_ids with the employee's grant.
     * null grant = company-wide (unchanged); [] = nowhere; list = site-scoped.
     *
     * @param  list<int>|null  $grantedSiteIds
     */
    public function constrainToGranted(?array $grantedSiteIds): self
    {
        if ($grantedSiteIds === null) {
            return $this;
        }

        if ($grantedSiteIds === []) {
            return new self(
                siteIds: [],
                from: $this->from,
                to: $this->to,
                asOf: $this->asOf,
                locale: $this->locale,
                platform: $this->platform,
                attributionWindow: $this->attributionWindow,
                acquisition: $this->acquisition,
            );
        }

        $requested = $this->siteIds;
        $siteIds = $requested === null
            ? $grantedSiteIds
            : array_values(array_intersect($grantedSiteIds, $requested));

        return new self(
            siteIds: $siteIds,
            from: $this->from,
            to: $this->to,
            asOf: $this->asOf,
            locale: $this->locale,
            platform: $this->platform,
            attributionWindow: $this->attributionWindow,
            acquisition: $this->acquisition,
        );
    }

    private static function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
