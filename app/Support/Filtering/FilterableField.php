<?php

declare(strict_types=1);

namespace App\Support\Filtering;

use Closure;

/**
 * One filterable field (native column or custom attribute) exposed in the schema.
 *
 * @phpstan-type FilterOption array{value: string|int|bool, label: string}
 */
final readonly class FilterableField
{
    /**
     * @param  'text'|'number'|'date'|'boolean'|'select'|'multiselect'|'email'|'consent'  $type
     * @param  list<string>  $operators
     * @param  list<FilterOption>|null  $options
     * @param  (Closure(mixed, string, mixed, string): void)|null  $apply  Relationship predicate. Args: query, operator, value, boolean.
     * @param  (Closure(): list<FilterOption>)|null  $optionsLoader
     * @param  list<FilterOption>|null  $channels
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public array $operators,
        public bool $custom = false,
        public ?array $options = null,
        public ?string $column = null,
        public ?Closure $apply = null,
        public ?Closure $optionsLoader = null,
        public ?array $channels = null,
    ) {}

    /** @return array{key: string, label: string, type: string, operators: list<string>, custom?: true, options?: list<FilterOption>, channels?: list<FilterOption>} */
    public function toSchemaArray(): array
    {
        $payload = [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type === 'email' ? 'text' : $this->type,
            'operators' => $this->operators,
        ];

        if ($this->custom) {
            $payload['custom'] = true;
        }

        $options = $this->optionsLoader !== null ? ($this->optionsLoader)() : $this->options;

        if ($options !== null) {
            $payload['options'] = $options;
        }

        if ($this->channels !== null) {
            $payload['channels'] = $this->channels;
        }

        return $payload;
    }
}
