<?php

declare(strict_types=1);

namespace App\Support\Layout;

final readonly class NativeField
{
    /**
     * @param  'text'|'number'|'date'|'boolean'|'select'|'email'  $type
     * @param  string|null  $optionsSource  e.g. 'deal_statuses', 'unit_classes'
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public bool $editable = true,
        public bool $required = false,
        public ?string $optionsSource = null,
    ) {}

    /** @return array{key: string, label: string, type: string, editable: bool, required: bool, options_source: string|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'editable' => $this->editable,
            'required' => $this->required,
            'options_source' => $this->optionsSource,
        ];
    }
}
