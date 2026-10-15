<?php

declare(strict_types=1);

namespace Database\Support;

use App\Support\Communications\EmailBlockDocument;
use InvalidArgumentException;

/**
 * Deterministic v2 email document builder for seeders and factories.
 * Ids are a counter hashed with the template name. No random draws.
 */
final class EmailDocBuilder
{
    private int $n = 0;

    /** @var array<string, true> */
    private array $used = [];

    /** @var list<array<string, mixed>> */
    private array $sections = [];

    private function __construct(private readonly string $seed) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function section(string $layout, callable ...$columns): self
    {
        if (! array_key_exists($layout, EmailBlockDocument::LAYOUTS)) {
            throw new InvalidArgumentException("Unknown email section layout [{$layout}].");
        }

        $expected = EmailBlockDocument::LAYOUTS[$layout];
        $given = count($columns);
        if ($given !== $expected) {
            throw new InvalidArgumentException("Layout [{$layout}] needs {$expected} columns, got {$given}.");
        }

        $sectionId = $this->mint('sec');
        $built = [];
        foreach ($columns as $callback) {
            $column = new EmailDocColumn($this);
            $columnId = $this->mint('col');
            $callback($column);
            $built[] = [
                'id' => $columnId,
                'blocks' => $column->blocks(),
            ];
        }

        $this->sections[] = [
            'id' => $sectionId,
            'layout' => $layout,
            'params' => [
                'background_color' => null,
                'padding' => 'md',
                'stack_on_mobile' => true,
            ],
            'columns' => $built,
        ];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return EmailBlockDocument::validate([
            'version' => EmailBlockDocument::VERSION_2,
            'settings' => ['background_color' => null],
            'sections' => $this->sections,
        ]);
    }

    public function mint(string $prefix): string
    {
        do {
            $this->n++;
            $id = $prefix.'_'.substr(hash('sha256', $this->seed.'|'.$this->n), 0, 8);
        } while (isset($this->used[$id]));

        $this->used[$id] = true;

        return $id;
    }
}

/**
 * One column inside {@see EmailDocBuilder}. Not autoloaded on its own;
 * the builder file loads it.
 */
final class EmailDocColumn
{
    /** @var list<array{id: string, type: string, params: array<string, mixed>}> */
    private array $blocks = [];

    public function __construct(private readonly EmailDocBuilder $builder) {}

    public function heading(string $text, int $level = 1): self
    {
        return $this->block('heading', [
            'text' => $text,
            'level' => $level,
        ]);
    }

    public function paragraph(string $html): self
    {
        return $this->block('paragraph', [
            'html' => $html,
        ]);
    }

    public function button(string $label, string $url, string $style = 'primary'): self
    {
        return $this->block('button', [
            'label' => $label,
            'url' => $url,
            'style' => $style,
        ]);
    }

    public function image(?int $assetId, ?string $url, ?string $alt, int $widthPercent): self
    {
        return $this->block('image', [
            'asset_id' => $assetId,
            'url' => $url,
            'alt' => $alt,
            'width_percent' => $widthPercent,
        ]);
    }

    public function divider(): self
    {
        return $this->block('divider', []);
    }

    public function spacer(int $height): self
    {
        return $this->block('spacer', [
            'height' => $height,
        ]);
    }

    public function unitSummary(): self
    {
        return $this->block('unit_summary', []);
    }

    public function rawHtml(string $html): self
    {
        return $this->block('raw_html', [
            'html' => $html,
        ]);
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    public function infoRows(array $rows, bool $striped, string $valueAlign): self
    {
        return $this->block('info_rows', [
            'rows' => $rows,
            'striped' => $striped,
            'value_align' => $valueAlign,
        ]);
    }

    /**
     * @param  list<string>  $items
     */
    public function itemList(string $style, array $items): self
    {
        return $this->block('list', [
            'style' => $style,
            'items' => $items,
        ]);
    }

    public function callout(string $tone, ?string $title, string $html): self
    {
        return $this->block('callout', [
            'tone' => $tone,
            'title' => $title,
            'html' => $html,
        ]);
    }

    public function logoHeader(string $align = 'center', int $maxWidth = 160, ?string $linkUrl = null): self
    {
        return $this->block('logo_header', [
            'align' => $align,
            'max_width' => $maxWidth,
            'link_url' => $linkUrl,
        ]);
    }

    /**
     * @param  list<array{network: string, url: string}>  $links
     */
    public function footerSocial(array $links, ?string $address = null): self
    {
        return $this->block('footer_social', [
            'links' => $links,
            'address' => $address,
        ]);
    }

    /**
     * @return list<array{id: string, type: string, params: array<string, mixed>}>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function block(string $type, array $params): self
    {
        $this->blocks[] = [
            'id' => $this->builder->mint('blk'),
            'type' => $type,
            'params' => $params,
        ];

        return $this;
    }
}
