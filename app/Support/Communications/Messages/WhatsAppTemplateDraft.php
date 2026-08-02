<?php

declare(strict_types=1);

namespace App\Support\Communications\Messages;

/**
 * Local registry content shaped for provider submission.
 */
final readonly class WhatsAppTemplateDraft
{
    /**
     * @param  list<array{type: string, text: string, url?: string|null}>|null  $buttons
     * @param  list<array{index: int, label: string, token_default?: string|null, sample?: string|null}>  $variables
     */
    public function __construct(
        public string $name,
        public string $language,
        public string $category,
        public string $body,
        public ?string $headerText = null,
        public ?string $footerText = null,
        public ?array $buttons = null,
        public array $variables = [],
    ) {}
}
