<?php

declare(strict_types=1);

namespace App\Support\Templates\Assist;

use App\Enums\TemplateChannel;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\EmailBrand;
use App\Support\Communications\SendClass;
use App\Support\Communications\TemplateFamilyUsage;
use App\Support\Templates\TokenCatalog;
use App\Support\Templates\TokenDefinition;

/**
 * Template facts the assistant may see. No CRM, billing, or message records.
 */
final readonly class AssistContext
{
    /**
     * @param  list<array{path: string, type: string, description: string, sample: string}>  $tokens
     * @param  list<array{type: string, params: list<array{name: string, rules: string}>}>  $blocks
     * @param  list<array{name: string, columns: int}>  $layouts
     * @param  array{text_color: string, background_color: string, accent_color: string, font: string, has_logo: bool}  $brand
     * @param  array{
     *     automations: array{count: int, names: list<string>},
     *     playbooks: array{count: int, names: list<string>},
     *     campaigns: array{count: int, names: list<string>}
     * }  $usedBy
     * @param  array{kind: string, id: string, path: string}|null  $selection
     */
    private function __construct(
        public string $channel,
        public string $purpose,
        public string $sendClass,
        public string $locale,
        public array $tokens,
        public array $blocks,
        public array $layouts,
        public array $brand,
        public array $usedBy,
        public ?array $selection,
    ) {}

    /**
     * @param  array{kind?: mixed, id?: mixed}|null  $selection
     * @param  array<string, mixed>|null  $document  Posted v2 document. Null uses the draft variant blocks.
     */
    public static function for(
        TemplateFamily $family,
        string $locale,
        ?array $selection = null,
        ?array $document = null,
    ): self {
        if ($family->channel !== TemplateChannel::Email) {
            throw AssistContextException::channelUnsupported();
        }

        $variant = self::draftVariant($family, $locale);
        $brand = EmailBrand::current();
        $blocks = is_array($variant->blocks) ? $variant->blocks : null;

        return new self(
            channel: $family->channel->value,
            purpose: $family->purpose->value,
            sendClass: SendClass::forTemplatePurpose($family->purpose)->value,
            locale: $locale,
            tokens: self::tokens($family, $locale),
            blocks: EmailBlockDocument::describeForAssistant(),
            layouts: self::layouts(),
            brand: [
                'text_color' => $brand->textColor,
                'background_color' => $brand->backgroundColor,
                'accent_color' => $brand->accentColor,
                'font' => $brand->fontStack,
                'has_logo' => $brand->logoAssetId !== null,
            ],
            usedBy: TemplateFamilyUsage::references($family),
            selection: self::resolveSelection($selection, $document ?? $blocks),
        );
    }

    public function toPromptBlock(): string
    {
        $lines = [
            'channel: '.$this->channel,
            'purpose: '.$this->purpose,
            'send_class: '.$this->sendClass,
            'locale: '.$this->locale,
            'tokens: '.$this->json($this->tokens),
            'blocks: '.$this->json($this->blocks),
            'layouts: '.$this->json($this->layouts),
            'brand: '.$this->json($this->brand),
            'used_by: '.$this->json($this->usedBy),
            'selection: '.$this->json($this->selection),
        ];

        return implode("\n", $lines);
    }

    private static function draftVariant(TemplateFamily $family, string $locale): TemplateVariant
    {
        $draft = $family->draft;
        if ($draft === null) {
            throw AssistContextException::localeMissing();
        }

        $variant = $draft->variants->firstWhere('locale', $locale);
        if (! $variant instanceof TemplateVariant) {
            throw AssistContextException::localeMissing();
        }

        return $variant;
    }

    /**
     * @return list<array{path: string, type: string, description: string, sample: string}>
     */
    private static function tokens(TemplateFamily $family, string $locale): array
    {
        return array_map(
            fn (TokenDefinition $token): array => [
                'path' => $token->path,
                'type' => $token->type->value,
                'description' => __($token->descriptionKey, [], $locale),
                'sample' => $token->sample,
            ],
            TokenCatalog::forPurpose($family->purpose),
        );
    }

    /**
     * @return list<array{name: string, columns: int}>
     */
    private static function layouts(): array
    {
        $layouts = [];
        foreach (EmailBlockDocument::LAYOUTS as $name => $columns) {
            $layouts[] = [
                'name' => $name,
                'columns' => $columns,
            ];
        }

        return $layouts;
    }

    /**
     * @param  array{kind?: mixed, id?: mixed}|null  $selection
     * @param  array<string, mixed>|null  $document
     * @return array{kind: string, id: string, path: string}|null
     */
    private static function resolveSelection(?array $selection, ?array $document): ?array
    {
        if ($selection === null || $document === null) {
            return null;
        }

        $kind = $selection['kind'] ?? null;
        $id = $selection['id'] ?? null;
        if (! is_string($kind) || ! is_string($id) || $id === '') {
            return null;
        }
        if (! in_array($kind, ['section', 'column', 'block'], true)) {
            return null;
        }

        $sections = $document['sections'] ?? null;
        if (! is_array($sections)) {
            return null;
        }

        foreach ($sections as $sectionIndex => $section) {
            if (! is_array($section)) {
                continue;
            }
            if ($kind === 'section' && ($section['id'] ?? null) === $id) {
                return self::selected($kind, $id, "sections[{$sectionIndex}]");
            }

            $columns = $section['columns'] ?? null;
            if (! is_array($columns)) {
                continue;
            }
            foreach ($columns as $columnIndex => $column) {
                if (! is_array($column)) {
                    continue;
                }
                if ($kind === 'column' && ($column['id'] ?? null) === $id) {
                    return self::selected($kind, $id, "sections[{$sectionIndex}].columns[{$columnIndex}]");
                }

                $blocks = $column['blocks'] ?? null;
                if (! is_array($blocks)) {
                    continue;
                }
                foreach ($blocks as $blockIndex => $block) {
                    if (! is_array($block)) {
                        continue;
                    }
                    if ($kind === 'block' && ($block['id'] ?? null) === $id) {
                        return self::selected(
                            $kind,
                            $id,
                            "sections[{$sectionIndex}].columns[{$columnIndex}].blocks[{$blockIndex}]",
                        );
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{kind: string, id: string, path: string}
     */
    private static function selected(string $kind, string $id, string $path): array
    {
        return [
            'kind' => $kind,
            'id' => $id,
            'path' => $path,
        ];
    }

    private function json(mixed $value): string
    {
        $encoded = json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : 'null';
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = self::sortKeys($item);
        }
        if (! array_is_list($value)) {
            ksort($sorted);
        }

        return $sorted;
    }
}
