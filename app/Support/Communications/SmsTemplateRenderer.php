<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\TemplateVariant;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;

/**
 * Renders an SMS template variant body with token resolution.
 */
final class SmsTemplateRenderer
{
    /**
     * @return array{text: string, warnings: list<string>}
     */
    public static function render(
        TemplateVariant $variant,
        RunContext $context,
        bool $previewMarkers = false,
    ): array {
        $resolved = TokenResolver::resolveCollectingWarnings(
            (string) ($variant->body_text ?? ''),
            $context,
            $previewMarkers,
        );

        return [
            'text' => $resolved['value'],
            'warnings' => $resolved['warnings'],
        ];
    }
}
