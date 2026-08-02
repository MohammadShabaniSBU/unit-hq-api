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
     */
    public function __construct(
        public ?array $siteIds = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $asOf = null,
        public string $locale = 'en',
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

        return new self(
            siteIds: $siteIds,
            from: self::nullableDate($validated['from'] ?? null),
            to: self::nullableDate($validated['to'] ?? null),
            asOf: self::nullableDate($validated['as_of'] ?? null),
            locale: $locale,
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
