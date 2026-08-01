<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\EmailBlock;
use App\Models\EmailTemplate;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;

/**
 * Renders an EmailTemplate's blocks to HTML/text (mirrors panel PreviewModal).
 */
final class EmailTemplateRenderer
{
    /**
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(
        EmailTemplate $template,
        RunContext $context,
        ?string $subjectOverride = null,
    ): array {
        $template->loadMissing('emailBlocks');

        $parts = [];
        foreach ($template->emailBlocks as $block) {
            $html = self::blockToHtml($block);
            if ($html !== '') {
                $parts[] = $html;
            }
        }

        $body = implode("\n", $parts);
        $html = '<div style="max-width:600px;margin:0 auto;font-family:sans-serif;background:#ffffff;">'
            .$body
            .'</div>';

        $html = TokenResolver::resolve($html, $context);
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $subject = $subjectOverride !== null && $subjectOverride !== ''
            ? TokenResolver::resolve($subjectOverride, $context)
            : TokenResolver::resolve($template->name, $context);

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
    }

    private static function blockToHtml(EmailBlock $block): string
    {
        /** @var array<string, mixed> $p */
        $p = $block->props ?? [];

        return match ($block->type) {
            'heading' => self::headingHtml($p),
            'text' => self::textHtml($p),
            'image' => self::imageHtml($p),
            'button' => self::buttonHtml($p),
            'divider' => self::dividerHtml($p),
            'spacer' => self::spacerHtml($p),
            default => '',
        };
    }

    /** @param  array<string, mixed>  $p */
    private static function headingHtml(array $p): string
    {
        $level = max(1, min(3, (int) ($p['level'] ?? 1)));
        $sizes = [1 => '32px', 2 => '24px', 3 => '20px'];
        $weights = [1 => '700', 2 => '600', 3 => '600'];
        $align = self::escape((string) ($p['align'] ?? 'left'));
        $color = self::escape((string) ($p['color'] ?? '#111827'));
        $content = self::escape((string) ($p['content'] ?? ''));

        return '<div style="text-align:'.$align.';padding:12px 24px;">'
            .'<h'.$level.' style="font-size:'.$sizes[$level].';font-weight:'.$weights[$level]
            .';color:'.$color.';line-height:1.3;margin:0;">'.$content.'</h'.$level.'>'
            .'</div>';
    }

    /** @param  array<string, mixed>  $p */
    private static function textHtml(array $p): string
    {
        $align = self::escape((string) ($p['align'] ?? 'left'));
        $fontSize = (int) ($p['fontSize'] ?? 16);
        $color = self::escape((string) ($p['color'] ?? '#000000'));
        $content = self::escape((string) ($p['content'] ?? ''));

        return '<div style="text-align:'.$align.';padding:12px 24px;">'
            .'<p style="font-size:'.$fontSize.'px;color:'.$color.';line-height:1.6;margin:0;">'
            .$content
            .'</p></div>';
    }

    /** @param  array<string, mixed>  $p */
    private static function imageHtml(array $p): string
    {
        $src = (string) ($p['src'] ?? '');
        if ($src === '') {
            return '';
        }

        $align = self::escape((string) ($p['align'] ?? 'center'));
        $alt = self::escape((string) ($p['alt'] ?? ''));
        $width = (int) ($p['width'] ?? 600);

        return '<div style="text-align:'.$align.';padding:12px 24px;">'
            .'<img src="'.self::escape($src).'" alt="'.$alt
            .'" style="max-width:'.$width.'px;width:100%;display:inline-block;" />'
            .'</div>';
    }

    /** @param  array<string, mixed>  $p */
    private static function buttonHtml(array $p): string
    {
        $align = self::escape((string) ($p['align'] ?? 'center'));
        $href = self::escape((string) ($p['href'] ?? '#'));
        $bg = self::escape((string) ($p['backgroundColor'] ?? '#3b82f6'));
        $textColor = self::escape((string) ($p['textColor'] ?? '#ffffff'));
        $label = self::escape((string) ($p['label'] ?? 'Click here'));

        return '<div style="text-align:'.$align.';padding:12px 24px;">'
            .'<a href="'.$href.'" style="display:inline-block;background-color:'.$bg
            .';color:'.$textColor.';padding:12px 24px;border-radius:8px;text-decoration:none;'
            .'font-weight:600;font-size:14px;">'.$label.'</a></div>';
    }

    /** @param  array<string, mixed>  $p */
    private static function dividerHtml(array $p): string
    {
        $color = self::escape((string) ($p['color'] ?? '#e5e7eb'));
        $thickness = (int) ($p['thickness'] ?? 1);

        return '<div style="padding:12px 24px;">'
            .'<hr style="border:none;border-top:'.$thickness.'px solid '.$color.';margin:0;" />'
            .'</div>';
    }

    /** @param  array<string, mixed>  $p */
    private static function spacerHtml(array $p): string
    {
        $height = (int) ($p['height'] ?? 24);

        return '<div style="height:'.$height.'px;"></div>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
