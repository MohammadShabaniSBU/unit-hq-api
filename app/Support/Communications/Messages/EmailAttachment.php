<?php

declare(strict_types=1);

namespace App\Support\Communications\Messages;

final readonly class EmailAttachment
{
    public function __construct(
        public string $filename,
        public string $content,
        public string $contentType,
    ) {}

    public function base64(): string
    {
        return base64_encode($this->content);
    }
}
