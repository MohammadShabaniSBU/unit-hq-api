<?php

declare(strict_types=1);

namespace App\Support\Communications\Messages;

/**
 * Pre-approved WhatsApp template send (allowed anytime to an opted-in number).
 */
final readonly class WhatsAppTemplateMessage
{
    /**
     * @param  list<string>  $variables  Positional body variables ({{1}}, {{2}}, …)
     */
    public function __construct(
        public string $to,
        public string $templateName,
        public string $language,
        public array $variables = [],
        public ?string $from = null,
    ) {}

    public function withSender(?string $from): self
    {
        return new self(
            to: $this->to,
            templateName: $this->templateName,
            language: $this->language,
            variables: $this->variables,
            from: $from ?? $this->from,
        );
    }
}
