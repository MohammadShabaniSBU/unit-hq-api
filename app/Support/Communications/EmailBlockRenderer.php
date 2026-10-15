<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\TemplateAsset;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;
use App\Support\Templates\TokenCatalog;
use App\Support\Templates\TokenType;
use InvalidArgumentException;

/**
 * Renders a v1 or v2 email block document to Outlook-safe table HTML + plain text.
 *
 * v1 is upcast in memory and rendered on the v2 path. One rendering path.
 * Golden fixtures target Outlook desktop — keep markup table-based with inline styles.
 */
final class EmailBlockRenderer
{
    private const FULL_WIDTH_PAD = 24;

    private const COLUMN_PAD = 12;

    private const STACK_STYLE = '<style type="text/css">@media (max-width: 480px) { .kv-col { max-width:100% !important; display:block !important; } }</style>';

    /**
     * @param  array<string, mixed>  $doc
     * @return array{html: string, text: string}
     */
    public static function render(array $doc, RunContext $context, string $accentColor, bool $previewMarkers = false): array
    {
        $accentColor = self::sanitizeColor($accentColor);
        $brand = EmailBrand::current();
        $version = $doc['version'] ?? null;
        if ($version === EmailBlockDocument::VERSION_1 || $version === (string) EmailBlockDocument::VERSION_1) {
            $doc = EmailBlockDocument::upcast($doc);
        }

        $pageBackground = self::pageBackground($doc, $brand);
        $htmlParts = [];
        $textParts = [];

        foreach ($doc['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            $rendered = self::section($section, $context, $accentColor, $previewMarkers, $brand);
            $htmlParts[] = $rendered['html'];
            if ($rendered['text'] !== '') {
                $textParts[] = $rendered['text'];
            }
        }

        $inner = implode("\n", $htmlParts);
        $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
            .'<html xmlns="http://www.w3.org/1999/xhtml"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
            .'<meta name="viewport" content="width=device-width, initial-scale=1.0" />'
            .'<title></title>'
            .self::STACK_STYLE
            .'</head><body style="margin:0;padding:0;background:'.$pageBackground.';">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:'.$pageBackground.';">'
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
     * @param  array<string, mixed>  $section
     * @return array{html: string, text: string}
     */
    private static function section(array $section, RunContext $context, string $accentColor, bool $previewMarkers, EmailBrand $brand): array
    {
        $layout = (string) ($section['layout'] ?? '100');
        $params = is_array($section['params'] ?? null) ? $section['params'] : [];
        $padding = (string) ($params['padding'] ?? 'md');
        $padPx = EmailLayout::PADDING_PX[$padding] ?? EmailLayout::PADDING_PX['md'];
        $stack = ($params['stack_on_mobile'] ?? true) === true;
        $widths = EmailLayout::columnWidths($layout, $padding);
        $columns = is_array($section['columns'] ?? null) ? array_values($section['columns']) : [];

        if (count($columns) !== count($widths)) {
            throw new InvalidArgumentException('Column count does not match layout preset.');
        }

        $styleParts = [];
        $background = $params['background_color'] ?? null;
        if (is_string($background) && $background !== '') {
            $styleParts[] = 'background:'.self::sanitizeColor($background);
        }
        $styleParts[] = 'padding:'.$padPx.'px';
        $styleParts[] = 'font-size:0';

        $chunks = [];
        $textParts = [];
        foreach ($columns as $index => $column) {
            if (! is_array($column)) {
                throw new InvalidArgumentException('Column must be an array.');
            }
            $width = $widths[$index];
            if ($index === 0) {
                $chunks[] = '<!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td width="'.$width.'" valign="top"><![endif]-->';
            } else {
                $chunks[] = '<!--[if mso]></td><td width="'.$width.'" valign="top"><![endif]-->';
            }
            $rendered = self::column($column, $width, $layout, $stack, $context, $accentColor, $previewMarkers, $brand);
            $chunks[] = $rendered['html'];
            if ($rendered['text'] !== '') {
                $textParts[] = $rendered['text'];
            }
        }
        $chunks[] = '<!--[if mso]></td></tr></table><![endif]-->';

        return [
            'html' => '<tr><td style="'.implode(';', $styleParts).'">'.implode('', $chunks).'</td></tr>',
            'text' => implode("\n\n", $textParts),
        ];
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array{html: string, text: string}
     */
    private static function column(
        array $column,
        int $width,
        string $layout,
        bool $stack,
        RunContext $context,
        string $accentColor,
        bool $previewMarkers,
        EmailBrand $brand,
    ): array {
        $padX = $layout === '100' ? self::FULL_WIDTH_PAD : self::COLUMN_PAD;
        $contentWidth = $layout === '100' ? EmailLayout::CONTAINER_WIDTH : $width;
        $blocks = is_array($column['blocks'] ?? null) ? $column['blocks'] : [];
        $rendered = self::blocks($blocks, $context, $accentColor, $previewMarkers, $padX, $contentWidth, $brand);

        $classes = [];
        if ($stack) {
            $classes[] = 'kv-col';
        }
        $classes[] = 'kv-col-'.$layout;

        $html = '<div class="'.implode(' ', $classes).'" style="display:inline-block;width:100%;max-width:'.$width.'px;vertical-align:top;font-size:16px;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            .$rendered['html']
            .'</table></div>';

        return ['html' => $html, 'text' => $rendered['text']];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array{html: string, text: string}
     */
    private static function blocks(
        array $blocks,
        RunContext $context,
        string $accentColor,
        bool $previewMarkers,
        int $padX,
        int $contentWidth,
        EmailBrand $brand,
    ): array {
        $htmlParts = [];
        $textParts = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $params = is_array($block['params'] ?? null) ? $block['params'] : [];
            $rendered = match ($type) {
                'heading' => self::heading($params, $context, $previewMarkers, $padX, $brand),
                'paragraph' => self::paragraph($params, $context, $previewMarkers, $padX, $brand),
                'button' => self::button($params, $context, $accentColor, $previewMarkers, $padX, $brand),
                'image' => self::image($params, $padX, $contentWidth),
                'divider' => self::divider($padX),
                'spacer' => self::spacer($params),
                'unit_summary' => self::unitSummary($context, $previewMarkers, $padX, $brand),
                'raw_html' => self::rawHtml($params, $context, $previewMarkers),
                'info_rows' => self::infoRows($params, $context, $previewMarkers, $padX, $brand),
                'list' => self::listBlock($params, $context, $previewMarkers, $padX, $brand),
                'callout' => self::callout($params, $context, $previewMarkers, $padX, $brand),
                'logo_header' => self::logoHeader($params, $context, $previewMarkers, $padX, $brand),
                'footer_social' => self::footerSocial($params, $context, $previewMarkers, $padX, $brand),
                default => ['html' => '', 'text' => ''],
            };
            if ($rendered['html'] !== '') {
                $htmlParts[] = $rendered['html'];
            }
            if ($rendered['text'] !== '') {
                $textParts[] = $rendered['text'];
            }
        }

        return [
            'html' => implode("\n", $htmlParts),
            'text' => implode("\n\n", $textParts),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function heading(array $params, RunContext $context, bool $previewMarkers, int $padX, EmailBrand $brand): array
    {
        $level = (int) ($params['level'] ?? 1);
        $level = $level === 2 ? 2 : 1;
        $sizes = [1 => '28px', 2 => '22px'];
        $text = self::resolve((string) ($params['text'] ?? ''), $context, $previewMarkers);
        $escaped = self::escape($text);
        $color = self::sanitizeColor($brand->textColor);

        $html = '<tr><td style="padding:16px '.$padX.'px 8px '.$padX.'px;font-family:'.$brand->fontFamily().';">'
            .'<h'.$level.' style="margin:0;font-size:'.$sizes[$level].';line-height:1.3;font-weight:700;color:'.$color.';">'
            .$escaped
            .'</h'.$level.'></td></tr>';

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function paragraph(array $params, RunContext $context, bool $previewMarkers, int $padX, EmailBrand $brand): array
    {
        $htmlIn = (string) ($params['html'] ?? '');
        $resolved = self::resolve($htmlIn, $context, $previewMarkers);
        $safe = self::sanitizeRichLite($resolved);
        $text = trim(html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $color = self::bodyColor($brand);

        $html = '<tr><td style="padding:8px '.$padX.'px;font-family:'.$brand->fontFamily().';font-size:16px;line-height:1.5;color:'.$color.';">'
            .$safe
            .'</td></tr>';

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function button(array $params, RunContext $context, string $accentColor, bool $previewMarkers, int $padX, EmailBrand $brand): array
    {
        $label = self::resolve((string) ($params['label'] ?? ''), $context, $previewMarkers);
        $url = self::resolve((string) ($params['url'] ?? '#'), $context, $previewMarkers);
        $style = (string) ($params['style'] ?? 'primary');
        $isOutline = $style === 'outline';

        $bg = $isOutline ? '#ffffff' : $accentColor;
        $color = $isOutline ? $accentColor : '#ffffff';
        $border = $accentColor;
        $font = $brand->fontFamily();
        $vmlFont = $brand->usesLegacyFont() ? 'Arial,sans-serif' : $font;

        // Bulletproof button (VML + table) — Outlook target.
        $html = '<tr><td align="center" style="padding:16px '.$padX.'px;">'
            .'<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="'
            .self::escapeAttr($url).'" style="height:44px;v-text-anchor:middle;width:220px;" arcsize="8%" strokecolor="'
            .self::escapeAttr($border).'" fillcolor="'.self::escapeAttr($bg).'">'
            .'<w:anchorlock/><center style="color:'.self::escapeAttr($color).';font-family:'.$vmlFont.';font-size:16px;font-weight:bold;">'
            .self::escape($label).'</center></v:roundrect><![endif]-->'
            .'<!--[if !mso]><!--><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td align="center" bgcolor="'.self::escapeAttr($bg).'" style="border-radius:4px;border:2px solid '.self::escapeAttr($border).';">'
            .'<a href="'.self::escapeAttr($url).'" target="_blank" style="display:inline-block;padding:12px 28px;font-family:'.$font.';'
            .'font-size:16px;font-weight:bold;color:'.self::escapeAttr($color).';text-decoration:none;">'
            .self::escape($label).'</a></td></tr></table><!--<![endif]-->'
            .'</td></tr>';

        return ['html' => $html, 'text' => $label.': '.$url];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function image(array $params, int $padX, int $contentWidth): array
    {
        $url = self::imageUrl($params);
        $alt = self::escape((string) ($params['alt'] ?? ''));
        $widthPercent = max(1, min(100, (int) ($params['width_percent'] ?? 100)));
        $widthPx = (int) round($contentWidth * ($widthPercent / 100));

        if ($url === '') {
            return ['html' => '', 'text' => ''];
        }

        $html = '<tr><td align="center" style="padding:12px '.$padX.'px;">'
            .'<img src="'.self::escapeAttr($url).'" alt="'.$alt.'" width="'.$widthPx.'" '
            .'style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;width:'.$widthPercent.'%;height:auto;" />'
            .'</td></tr>';

        $text = $alt !== '' ? '[image: '.$alt.']' : '[image]';

        return ['html' => $html, 'text' => $text];
    }

    /** @return array{html: string, text: string} */
    private static function divider(int $padX): array
    {
        $html = '<tr><td style="padding:12px '.$padX.'px;">'
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
    private static function unitSummary(RunContext $context, bool $previewMarkers, int $padX, EmailBrand $brand): array
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

        $color = self::sanitizeColor($brand->textColor);
        $html = '<tr><td style="padding:12px '.$padX.'px;font-family:'.$brand->fontFamily().';">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f9fafb;border:1px solid #e5e7eb;">'
            .'<tr><td style="padding:16px;font-size:16px;line-height:1.4;color:'.$color.';">'.$body.'</td></tr>'
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
     * @param  array<string, mixed>  $doc
     */
    private static function pageBackground(array $doc, EmailBrand $brand): string
    {
        $settings = $doc['settings'] ?? null;
        $color = is_array($settings) ? ($settings['background_color'] ?? null) : null;
        if (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
            return self::sanitizeColor($color);
        }

        return self::sanitizeColor($brand->backgroundColor);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function infoRows(array $params, RunContext $context, bool $previewMarkers, int $padX, EmailBrand $brand): array
    {
        $rows = is_array($params['rows'] ?? null) ? $params['rows'] : [];
        $striped = ($params['striped'] ?? false) === true;
        $align = ($params['value_align'] ?? 'left') === 'right' ? 'right' : 'left';
        $labelColor = self::bodyColor($brand);
        $valueColor = self::sanitizeColor($brand->textColor);
        $font = $brand->fontFamily();

        $htmlRows = [];
        $textLines = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = self::resolve((string) ($row['label'] ?? ''), $context, $previewMarkers);
            $value = self::resolveInfoValue((string) ($row['value'] ?? ''), $context, $previewMarkers);
            $bg = $striped && $index % 2 === 0 ? 'background:#f9fafb;' : '';
            $htmlRows[] = '<tr>'
                .'<td style="padding:8px 12px;'.$bg.'font-family:'.$font.';font-size:14px;line-height:1.4;color:'.$labelColor.';">'.self::escape($label).'</td>'
                .'<td align="'.$align.'" style="padding:8px 12px;'.$bg.'font-family:'.$font.';font-size:14px;line-height:1.4;color:'.$valueColor.';text-align:'.$align.';">'.self::escape($value).'</td>'
                .'</tr>';
            $textLines[] = $label.': '.$value;
        }

        $html = '<tr><td style="padding:8px '.$padX.'px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            .implode('', $htmlRows)
            .'</table></td></tr>';

        return ['html' => $html, 'text' => implode("\n", $textLines)];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function listBlock(array $params, RunContext $context, bool $previewMarkers, int $padX, EmailBrand $brand): array
    {
        $items = is_array($params['items'] ?? null) ? $params['items'] : [];
        $numbered = ($params['style'] ?? 'bullet') === 'numbered';
        $color = self::bodyColor($brand);
        $font = $brand->fontFamily();
        $htmlRows = [];
        $textLines = [];

        $n = 0;
        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }
            $n++;
            $resolved = self::resolve($item, $context, $previewMarkers);
            $safe = self::sanitizeRichLite($resolved);
            $plain = trim(html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $marker = $numbered ? $n.'.' : '-';
            $htmlRows[] = '<tr>'
                .'<td width="28" valign="top" style="padding:2px 8px 2px 0;font-family:'.$font.';font-size:16px;line-height:1.5;color:'.$color.';">'.self::escape($marker).'</td>'
                .'<td valign="top" style="padding:2px 0;font-family:'.$font.';font-size:16px;line-height:1.5;color:'.$color.';">'.$safe.'</td>'
                .'</tr>';
            $textLines[] = $marker.' '.$plain;
        }

        $html = '<tr><td style="padding:8px '.$padX.'px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            .implode('', $htmlRows)
            .'</table></td></tr>';

        return ['html' => $html, 'text' => implode("\n", $textLines)];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function callout(array $params, RunContext $context, bool $previewMarkers, int $padX, EmailBrand $brand): array
    {
        $tone = (string) ($params['tone'] ?? 'info');
        $palette = match ($tone) {
            'warning' => ['bg' => '#fffbeb', 'border' => '#fde68a'],
            'danger' => ['bg' => '#fef2f2', 'border' => '#fecaca'],
            default => ['bg' => '#eff6ff', 'border' => '#bfdbfe'],
        };
        $titleRaw = $params['title'] ?? null;
        $title = is_string($titleRaw) && $titleRaw !== ''
            ? self::resolve($titleRaw, $context, $previewMarkers)
            : '';
        $resolved = self::resolve((string) ($params['html'] ?? ''), $context, $previewMarkers);
        $safe = self::sanitizeRichLite($resolved);
        $plain = trim(html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $color = self::bodyColor($brand);
        $font = $brand->fontFamily();

        $titleHtml = $title !== ''
            ? '<strong style="display:block;margin:0 0 4px 0;">'.self::escape($title).'</strong>'
            : '';

        $html = '<tr><td style="padding:8px '.$padX.'px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:'.$palette['bg'].';border:1px solid '.$palette['border'].';">'
            .'<tr><td style="padding:16px;font-family:'.$font.';font-size:16px;line-height:1.5;color:'.$color.';">'
            .$titleHtml.$safe
            .'</td></tr></table></td></tr>';

        $text = $title !== '' ? $title."\n".$plain : $plain;

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function logoHeader(array $params, RunContext $context, bool $previewMarkers, int $padX, EmailBrand $brand): array
    {
        $url = $brand->logoUrl();
        if ($url === null || $url === '') {
            if (! $previewMarkers) {
                return ['html' => '', 'text' => ''];
            }
            $marker = '⟦missing:brand.logo⟧';

            return [
                'html' => '<tr><td style="padding:12px '.$padX.'px;">'.self::escape($marker).'</td></tr>',
                'text' => $marker,
            ];
        }

        $align = ($params['align'] ?? 'left') === 'center' ? 'center' : 'left';
        $maxWidth = max(60, min(300, (int) ($params['max_width'] ?? 160)));
        $img = '<img src="'.self::escapeAttr($url).'" alt="" width="'.$maxWidth.'" style="display:block;border:0;outline:none;text-decoration:none;max-width:'.$maxWidth.'px;width:100%;height:auto;" />';
        $link = $params['link_url'] ?? null;
        if (is_string($link) && $link !== '') {
            $href = self::resolve($link, $context, $previewMarkers);
            $img = '<a href="'.self::escapeAttr($href).'" target="_blank">'.$img.'</a>';
        }

        $html = '<tr><td align="'.$align.'" style="padding:16px '.$padX.'px 8px '.$padX.'px;">'.$img.'</td></tr>';

        return ['html' => $html, 'text' => ''];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{html: string, text: string}
     */
    private static function footerSocial(array $params, RunContext $context, bool $previewMarkers, int $padX, EmailBrand $brand): array
    {
        $links = is_array($params['links'] ?? null) ? $params['links'] : [];
        $addressRaw = $params['address'] ?? null;
        $address = is_string($addressRaw) && $addressRaw !== ''
            ? self::resolve($addressRaw, $context, $previewMarkers)
            : '';
        $font = $brand->fontFamily();
        $icons = [];
        $textLines = [];

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }
            $network = (string) ($link['network'] ?? '');
            $href = self::resolve((string) ($link['url'] ?? ''), $context, $previewMarkers);
            $icon = SocialIcon::url($network);
            $label = $network;
            $inner = $icon !== null
                ? '<img src="'.self::escapeAttr($icon).'" alt="'.self::escapeAttr($label).'" width="20" height="20" style="display:block;border:0;width:20px;height:20px;" />'
                : self::escape($label);
            $icons[] = '<a href="'.self::escapeAttr($href).'" target="_blank" style="display:inline-block;margin:0 6px;text-decoration:none;color:#6b7280;">'.$inner.'</a>';
            $textLines[] = $label.': '.$href;
        }

        if ($icons === [] && $address === '') {
            return ['html' => '', 'text' => ''];
        }

        $addressHtml = $address !== ''
            ? '<div style="margin-top:8px;">'.self::escape($address).'</div>'
            : '';
        if ($address !== '') {
            $textLines[] = $address;
        }

        $html = '<tr><td align="center" style="padding:12px '.$padX.'px;font-family:'.$font.';font-size:12px;line-height:1.4;color:#6b7280;">'
            .implode('', $icons)
            .$addressHtml
            .'</td></tr>';

        return ['html' => $html, 'text' => implode("\n", $textLines)];
    }

    private static function resolveInfoValue(string $raw, RunContext $context, bool $previewMarkers): string
    {
        $resolved = self::resolve($raw, $context, $previewMarkers);
        if (preg_match('/^\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}$/', trim($raw), $matches) !== 1) {
            return $resolved;
        }

        $token = TokenCatalog::find($matches[1]);
        if ($token === null || $token->type !== TokenType::Money) {
            return $resolved;
        }

        $currency = self::resolve('{{contract.currency}}', $context, $previewMarkers);
        if ($currency !== '' && $currency !== $resolved) {
            return trim($resolved.' '.$currency);
        }

        return $resolved;
    }

    private static function bodyColor(EmailBrand $brand): string
    {
        if ($brand->usesLegacyText()) {
            return '#374151';
        }

        return self::sanitizeColor($brand->textColor);
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
