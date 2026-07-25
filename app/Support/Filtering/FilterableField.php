<?php

declare(strict_types=1);

namespace App\Support\Filtering;

/**
 * One filterable field (native column or custom attribute) exposed in the schema.
 *
 * @phpstan-type FilterOption array{value: string|int|bool, label: string}
 */
final readonly class FilterableField
{
    /**
     * @param  'text'|'number'|'date'|'boolean'|'select'|'multiselect'|'email'  $type
     * @param  list<string>  $operators
     * @param  list<FilterOption>|null  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public array $operators,
        public bool $custom = false,
        public ?array $options = null,
        public ?string $column = null,
    ) {}

    /** @return array{key: string, label: string, type: string, operators: list<string>, custom?: true, options?: list<FilterOption>} */
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

        if ($this->options !== null) {
            $payload['options'] = $this->options;
        }

        return $payload;
    }
}
