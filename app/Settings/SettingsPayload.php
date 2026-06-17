<?php

namespace App\Settings;

interface SettingsPayload
{
    public static function default(): static;

    public static function fromArray(array $data): static;

    public function toArray(): array;
}
