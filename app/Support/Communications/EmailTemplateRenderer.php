<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\TemplateVariant;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;
use RuntimeException;

/**
 * Renders a template variant to email HTML/text.
 * Legacy variants use legacy_html passthrough; v2 blocks are reserved for S13-01.
 */
final class EmailTemplateRenderer
{
    /**
     * @return array{subject: string, html: string, text: string, warnings: list<string>}
     */
    public static function render(
        TemplateVariant $variant,
        RunContext $context,
        ?string $subjectOverride = null,
        bool $previewMarkers = false,
    ): array {
        $htmlSource = self::htmlSource($variant);
        $htmlResolved = TokenResolver::resolveCollectingWarnings($htmlSource, $context, $previewMarkers);
        $html = $htmlResolved['value'];
        $warnings = $htmlResolved['warnings'];

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $subjectSource = $subjectOverride !== null && $subjectOverride !== ''
            ? $subjectOverride
            : (string) ($variant->subject ?? '');
        $subjectResolved = TokenResolver::resolveCollectingWarnings($subjectSource, $context, $previewMarkers);
        foreach ($subjectResolved['warnings'] as $path) {
            if (! in_array($path, $warnings, true)) {
                $warnings[] = $path;
            }
        }

        return [
            'subject' => $subjectResolved['value'],
            'html' => $html,
            'text' => $text,
            'warnings' => $warnings,
        ];
    }

    private static function htmlSource(TemplateVariant $variant): string
    {
        if (is_string($variant->legacy_html) && $variant->legacy_html !== '') {
            return $variant->legacy_html;
        }

        if (is_array($variant->blocks) && $variant->blocks !== []) {
            throw new RuntimeException(
                'v2 block documents require EmailBlockRenderer (S13-01); variant has blocks without legacy_html.'
            );
        }

        return '';
    }
}
