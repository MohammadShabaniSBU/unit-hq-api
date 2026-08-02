<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Setting;
use App\Models\TemplateVariant;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;

/**
 * Renders a template variant to email HTML/text.
 * Legacy variants use legacy_html passthrough; v2 blocks go through EmailBlockRenderer.
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
        $source = self::htmlSource($variant, $context, $previewMarkers);
        $html = $source['html'];
        $text = $source['text'];
        $warnings = $source['warnings'];

        if ($source['needs_token_pass']) {
            $htmlResolved = TokenResolver::resolveCollectingWarnings($html, $context, $previewMarkers);
            $html = $htmlResolved['value'];
            $warnings = $htmlResolved['warnings'];
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

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

    /**
     * @return array{html: string, text: string, warnings: list<string>, needs_token_pass: bool}
     */
    private static function htmlSource(
        TemplateVariant $variant,
        RunContext $context,
        bool $previewMarkers,
    ): array {
        if (is_string($variant->legacy_html) && $variant->legacy_html !== '') {
            return [
                'html' => $variant->legacy_html,
                'text' => '',
                'warnings' => [],
                'needs_token_pass' => true,
            ];
        }

        if (is_array($variant->blocks) && $variant->blocks !== []) {
            $doc = EmailBlockDocument::validate($variant->blocks);
            $accent = Setting::general()->emailAccentColor;
            $rendered = EmailBlockRenderer::render($doc, $context, $accent, $previewMarkers);

            // Collect warnings by re-scanning source strings for unresolved tokens.
            $warnings = self::collectBlockWarnings($doc, $context);

            return [
                'html' => $rendered['html'],
                'text' => $rendered['text'],
                'warnings' => $warnings,
                'needs_token_pass' => false,
            ];
        }

        return [
            'html' => '',
            'text' => '',
            'warnings' => [],
            'needs_token_pass' => false,
        ];
    }

    /**
     * @param  array{version: int, blocks: list<array{id: string, type: string, params: array<string, mixed>}>}  $doc
     * @return list<string>
     */
    private static function collectBlockWarnings(array $doc, RunContext $context): array
    {
        $warnings = [];
        foreach ($doc['blocks'] as $block) {
            foreach ($block['params'] as $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }
                $resolved = TokenResolver::resolveCollectingWarnings($value, $context, false);
                foreach ($resolved['warnings'] as $path) {
                    if (! in_array($path, $warnings, true)) {
                        $warnings[] = $path;
                    }
                }
            }
        }

        // unit_summary always references these paths
        foreach ($doc['blocks'] as $block) {
            if ($block['type'] !== 'unit_summary') {
                continue;
            }
            foreach (['contract.unit_name', 'contract.unit_rate', 'contract.currency'] as $path) {
                if ($context->get($path) === null && ! in_array($path, $warnings, true)) {
                    $warnings[] = $path;
                }
            }
        }

        return $warnings;
    }
}
