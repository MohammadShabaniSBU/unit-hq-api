<?php

declare(strict_types=1);

namespace App\Support\Communications;

use Illuminate\Validation\ValidationException;

/**
 * Form teeth for WhatsApp template drafts: slug, placeholders, samples.
 */
final class WhatsAppTemplateValidator
{
    public const CATEGORIES = ['utility', 'marketing', 'authentication'];

    public const NAME_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     name: string,
     *     language: string,
     *     category: string,
     *     header_text: string|null,
     *     body: string,
     *     footer_text: string|null,
     *     buttons: list<array{type: string, text: string, url?: string|null}>|null,
     *     variables: list<array{index: int, label: string, token_default: string|null, sample: string|null}>
     * }
     */
    public static function validate(array $data, bool $requireSamples = false): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || strlen($name) > 128) {
            $errors['name'][] = 'Name is required (max 128 characters).';
        } elseif (! preg_match(self::NAME_PATTERN, $name)) {
            $errors['name'][] = 'Name must be a Meta-safe slug (lowercase letters, digits, underscores).';
        }

        $language = trim((string) ($data['language'] ?? ''));
        if ($language === '' || strlen($language) > 8) {
            $errors['language'][] = 'Language is required (Meta language code).';
        }

        $category = trim((string) ($data['category'] ?? ''));
        if (! in_array($category, self::CATEGORIES, true)) {
            $errors['category'][] = 'Category must be utility, marketing, or authentication.';
        }

        $header = isset($data['header_text']) && $data['header_text'] !== null && $data['header_text'] !== ''
            ? (string) $data['header_text']
            : null;
        if ($header !== null && mb_strlen($header) > 60) {
            $errors['header_text'][] = 'Header text must be at most 60 characters.';
        }

        $body = (string) ($data['body'] ?? '');
        if (trim($body) === '') {
            $errors['body'][] = 'Body is required.';
        } elseif (mb_strlen($body) > 1024) {
            $errors['body'][] = 'Body must be at most 1024 characters.';
        }

        $footer = isset($data['footer_text']) && $data['footer_text'] !== null && $data['footer_text'] !== ''
            ? (string) $data['footer_text']
            : null;
        if ($footer !== null && mb_strlen($footer) > 60) {
            $errors['footer_text'][] = 'Footer text must be at most 60 characters.';
        }

        $placeholders = self::extractPlaceholders($body);
        $variablesInput = $data['variables'] ?? [];
        if (! is_array($variablesInput)) {
            $errors['variables'][] = 'Variables must be an array.';
            $variablesInput = [];
        }

        /** @var array<int, array{index: int, label: string, token_default: string|null, sample: string|null}> $byIndex */
        $byIndex = [];
        foreach ($variablesInput as $i => $row) {
            if (! is_array($row)) {
                $errors["variables.{$i}"][] = 'Each variable must be an object.';

                continue;
            }
            $index = (int) ($row['index'] ?? 0);
            if ($index < 1) {
                $errors["variables.{$i}.index"][] = 'Variable index must be a positive integer.';

                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $errors["variables.{$i}.label"][] = 'Variable label is required.';
            }
            $sample = array_key_exists('sample', $row) && $row['sample'] !== null && $row['sample'] !== ''
                ? (string) $row['sample']
                : null;
            $tokenDefault = array_key_exists('token_default', $row) && $row['token_default'] !== null && $row['token_default'] !== ''
                ? (string) $row['token_default']
                : null;

            $byIndex[$index] = [
                'index' => $index,
                'label' => $label,
                'token_default' => $tokenDefault,
                'sample' => $sample,
            ];
        }

        if ($placeholders !== []) {
            $expected = range(1, max($placeholders));
            if ($placeholders !== $expected) {
                $errors['body'][] = 'Placeholders must be sequential starting at {{1}} with no gaps.';
            }

            foreach ($expected as $n) {
                if (! isset($byIndex[$n])) {
                    $errors['variables'][] = "Missing variable row for placeholder {{{$n}}}.";

                    continue;
                }
                if ($requireSamples && ($byIndex[$n]['sample'] === null || $byIndex[$n]['sample'] === '')) {
                    $errors["variables.{$n}.sample"][] = "Sample is required for placeholder {{{$n}}} before submit.";
                }
            }
        } elseif ($byIndex !== []) {
            $errors['variables'][] = 'Variables were provided but the body has no {{n}} placeholders.';
        }

        $extraIndexes = array_diff(array_keys($byIndex), $placeholders === [] ? [] : range(1, max($placeholders)));
        foreach ($extraIndexes as $extra) {
            $errors['variables'][] = "Variable index {$extra} has no matching placeholder in the body.";
        }

        $buttons = null;
        if (array_key_exists('buttons', $data) && $data['buttons'] !== null) {
            if (! is_array($data['buttons'])) {
                $errors['buttons'][] = 'Buttons must be an array.';
            } else {
                $buttons = [];
                foreach ($data['buttons'] as $i => $button) {
                    if (! is_array($button)) {
                        $errors["buttons.{$i}"][] = 'Each button must be an object.';

                        continue;
                    }
                    $type = strtolower((string) ($button['type'] ?? ''));
                    if (! in_array($type, ['url', 'quick_reply'], true)) {
                        $errors["buttons.{$i}.type"][] = 'Button type must be url or quick_reply.';
                    }
                    $text = trim((string) ($button['text'] ?? ''));
                    if ($text === '') {
                        $errors["buttons.{$i}.text"][] = 'Button text is required.';
                    }
                    $url = isset($button['url']) && is_string($button['url']) ? $button['url'] : null;
                    if ($type === 'url' && ($url === null || $url === '')) {
                        $errors["buttons.{$i}.url"][] = 'URL buttons require a url.';
                    }
                    $entry = ['type' => $type, 'text' => $text];
                    if ($url !== null) {
                        $entry['url'] = $url;
                    }
                    $buttons[] = $entry;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        ksort($byIndex);

        return [
            'name' => $name,
            'language' => $language,
            'category' => $category,
            'header_text' => $header,
            'body' => $body,
            'footer_text' => $footer,
            'buttons' => $buttons,
            'variables' => array_values($byIndex),
        ];
    }

    /**
     * @return list<int>
     */
    public static function extractPlaceholders(string $body): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        $indexes = array_map('intval', $matches[1] ?? []);
        $indexes = array_values(array_unique($indexes));
        sort($indexes);

        return $indexes;
    }
}
