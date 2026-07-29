<?php

declare(strict_types=1);

namespace App\Support\Communications\Messages;

final readonly class EmailAddress
{
    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {}

    public function formatted(): string
    {
        if ($this->name === null || $this->name === '') {
            return $this->email;
        }

        return sprintf('%s <%s>', $this->name, $this->email);
    }
}
