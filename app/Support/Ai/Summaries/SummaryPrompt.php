<?php

declare(strict_types=1);

namespace App\Support\Ai\Summaries;

use RuntimeException;

final class SummaryPrompt
{
    public static function assemble(string $entity, string $locale, array $context): string
    {
        $path = resource_path("prompts/summary/{$entity}.v1.md");

        if (! is_file($path)) {
            throw new RuntimeException("Missing summary prompt template: {$path}");
        }

        $template = file_get_contents($path);
        if ($template === false) {
            throw new RuntimeException("Unable to read summary prompt template: {$path}");
        }

        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return str_replace(
            ['{{locale}}', '{{context}}'],
            [$locale, $encoded],
            $template,
        );
    }
}
