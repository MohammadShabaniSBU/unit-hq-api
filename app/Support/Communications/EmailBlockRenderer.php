<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\TemplateAsset;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;

/**
 * Renders a v1 email block document to Outlook-safe table HTML + plain text.
 *
 * Golden fixtures target Outlook desktop — keep markup table-based with inline styles.
 */
final class EmailBlockRenderer
{
    /**
     * @param  array{version: int, blocks: list<array{id: string, type: string, params: array<string, mixed>}>}  $doc
     * @return array{html: string, text: string}
     */
    public static function render(array $doc, RunContext $context, string $accentColor, bool $previewMarkers = false): array
    {
        $accentColor = self::sanitizeColor($accentColor);
        $htmlParts = [];
        $textParts = [];

        foreach ($doc['blocks'] as $block) {
            $type = $block['type'];
            $params = $block['params'];
            $rendered = match ($type) {
                'heading' => self::heading($params, $context, $previewMarkers),
                'paragraph' => self::paragraph($params, $context, $previewMarkers),
                'button' => self::button($params, $context, $accentColor, $previewMarkers),
                'image' => self::image($params),
                'divider' => self::divider(),
                'spacer' => self::spacer($params),
                'unit_summary' => self::unitSummary($context, $previewMarkers),
                'raw_html' => self::rawHtml($params, $context, $previewMarkers),
                default => ['html' => '', 'text' => ''],
            };
            if ($rendered['html'] !== '') {
                $htmlParts[] = $rendered['html'];
            }
            if ($rendered['text'] !== '') {
                $textParts[] = $rendered['text'];
            }
        }

        $inner = implode("\n", $htmlParts);
        $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
            .'<html xmlns="http://www.w3.org/1999/xhtml"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
            .'<meta name="viewport" content="width=device-width, initial-scale=1.0" />'
            .'<title></title></head><body style="margin:0;padding:0;background:#f3f4f6;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f3f4f6;">'
            .'<tr><td align="center" style="padding:24px 12px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:600px;max-width:600px;background:#ffffff;">'
            .$inner
            .'</table></td></tr></table></body></html>';

        return [
            'html' => $html,
            'text' => trim(implode("\n\n", $textParts)),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function heading(array $params, RunContext $context, bool $previewMarkers): array
    {
        $level = (int) ($params['level'] ?? 1);
        $level = $level === 2 ? 2 : 1;
        $sizes = [1 => '28px', 2 => '22px'];
        $text = self::resolve((string) ($params['text'] ?? ''), $context, $previewMarkers);
        $escaped = self::escape($text);

        $html = '<tr><td style="padding:16px 24px 8px 24px;font-family:Arial,Helvetica,sans-serif;">'
            .'<h'.$level.' style="margin:0;font-size:'.$sizes[$level].';line-height:1.3;font-weight:700;color:#111827;">'
            .$escaped
            .'</h'.$level.'></td></tr>';

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function paragraph(array $params, RunContext $context, bool $previewMarkers): array
    {
        $htmlIn = (string) ($params['html'] ?? '');
        $resolved = self::resolve($htmlIn, $context, $previewMarkers);
        $safe = self::sanitizeRichLite($resolved);
        $text = trim(html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $html = '<tr><td style="padding:8px 24px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#374151;">'
            .$safe
            .'</td></tr>';

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function button(array $params, RunContext $context, string $accentColor, bool $previewMarkers): array
    {
        $label = self::resolve((string) ($params['label'] ?? ''), $context, $previewMarkers);
        $url = self::resolve((string) ($params['url'] ?? '#'), $context, $previewMarkers);
        $style = (string) ($params['style'] ?? 'primary');
        $isOutline = $style === 'outline';

        $bg = $isOutline ? '#ffffff' : $accentColor;
        $color = $isOutline ? $accentColor : '#ffffff';
        $border = $accentColor;

        // Bulletproof button (VML + table) — Outlook target.
        $html = '<tr><td align="center" style="padding:16px 24px;">'
            .'<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="'
            .self::escapeAttr($url).'" style="height:44px;v-text-anchor:middle;width:220px;" arcsize="8%" strokecolor="'
            .self::escapeAttr($border).'" fillcolor="'.self::escapeAttr($bg).'">'
            .'<w:anchorlock/><center style="color:'.self::escapeAttr($color).';font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">'
            .self::escape($label).'</center></v:roundrect><![endif]-->'
            .'<!--[if !mso]><!--><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td align="center" bgcolor="'.self::escapeAttr($bg).'" style="border-radius:4px;border:2px solid '.self::escapeAttr($border).';">'
            .'<a href="'.self::escapeAttr($url).'" target="_blank" style="display:inline-block;padding:12px 28px;font-family:Arial,Helvetica,sans-serif;'
            .'font-size:16px;font-weight:bold;color:'.self::escapeAttr($color).';text-decoration:none;">'
            .self::escape($label).'</a></td></tr></table><!--<![endif]-->'
            .'</td></tr>';

        return ['html' => $html, 'text' => $label.': '.$url];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function image(array $params): array
    {
        $url = self::imageUrl($params);
        $alt = self::escape((string) ($params['alt'] ?? ''));
        $widthPercent = max(1, min(100, (int) ($params['width_percent'] ?? 100)));
        $widthPx = (int) round(600 * ($widthPercent / 100));

        if ($url === '') {
            return ['html' => '', 'text' => ''];
        }

        $html = '<tr><td align="center" style="padding:12px 24px;">'
            .'<img src="'.self::escapeAttr($url).'" alt="'.$alt.'" width="'.$widthPx.'" '
            .'style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;width:'.$widthPercent.'%;height:auto;" />'
            .'</td></tr>';

        $text = $alt !== '' ? '[image: '.$alt.']' : '[image]';

        return ['html' => $html, 'text' => $text];
    }

    /** @return array{html: string, text: string} */
    private static function divider(): array
    {
        $html = '<tr><td style="padding:12px 24px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'
            .'<td style="border-top:1px solid #e5e7eb;font-size:0;line-height:0;">&nbsp;</td>'
            .'</tr></table></td></tr>';

        return ['html' => $html, 'text' => '---'];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function spacer(array $params): array
    {
        $height = max(4, min(200, (int) ($params['height'] ?? 24)));
        $html = '<tr><td style="height:'.$height.'px;line-height:'.$height.'px;font-size:0;">&nbsp;</td></tr>';

        return ['html' => $html, 'text' => ''];
    }

    /** @return array{html: string, text: string} */
    private static function unitSummary(RunContext $context, bool $previewMarkers): array
    {
        $unitName = self::resolve('{{contract.unit_name}}', $context, $previewMarkers);
        $unitRate = self::resolve('{{contract.unit_rate}}', $context, $previewMarkers);
        $currency = self::resolve('{{contract.currency}}', $context, $previewMarkers);

        $line = trim($unitName);
        $rateLine = trim($unitRate.($currency !== '' && $currency !== $unitRate ? ' '.$currency : ''));

        $body = self::escape($line);
        if ($rateLine !== '') {
            $body .= '<br /><span style="color:#6b7280;font-size:14px;">'.self::escape($rateLine).'</span>';
        }

        $html = '<tr><td style="padding:12px 24px;font-family:Arial,Helvetica,sans-serif;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f9fafb;border:1px solid #e5e7eb;">'
            .'<tr><td style="padding:16px;font-size:16px;line-height:1.4;color:#111827;">'.$body.'</td></tr>'
            .'</table></td></tr>';

        $text = trim($line.($rateLine !== '' ? "\n".$rateLine : ''));

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function rawHtml(array $params, RunContext $context, bool $previewMarkers): array
    {
        $htmlIn = (string) ($params['html'] ?? '');
        $resolved = self::resolve($htmlIn, $context, $previewMarkers);
        $text = trim(html_entity_decode(strip_tags($resolved), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $html = '<tr><td style="padding:0;">'.$resolved.'</td></tr>';

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function imageUrl(array $params): string
    {
        $assetId = $params['asset_id'] ?? null;
        if (is_numeric($assetId)) {
            $asset = TemplateAsset::query()->find((int) $assetId);
            if ($asset instanceof TemplateAsset) {
                return $asset->publicUrl();
            }
        }

        return (string) ($params['url'] ?? '');
    }

    private static function resolve(string $template, RunContext $context, bool $previewMarkers): string
    {
        return TokenResolver::resolveCollectingWarnings($template, $context, $previewMarkers)['value'];
    }

    private static function sanitizeRichLite(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><a><ul><ol><li>';
        $clean = strip_tags($html, $allowed);

        return (string) preg_replace_callback(
            '/<a\s+([^>]*?)>/i',
            static function (array $matches): string {
                if (preg_match('/href\s*=\s*([\'"])(.*?)\1/i', $matches[1], $href) !== 1) {
                    return '<a>';
                }
                $url = self::escapeAttr($href[2]);

                return '<a href="'.$url.'" target="_blank" style="color:#1d4ed8;text-decoration:underline;">';
            },
            $clean,
        );
    }

    private static function sanitizeColor(string $color): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
            return strtolower($color);
        }

        return '#1d4ed8';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
