<?php

declare(strict_types=1);

namespace App\Support\Ai\Eval;

use App\Support\Ai\Tools\AgentTool;
use JsonException;

final class CassetteKey
{
    /**
     * @param  list<AgentTool>  $tools
     * @return array{prompt_hash: string, schema_hash: string}
     */
    public static function hashes(string $systemPrompt, array $tools): array
    {
        return [
            'prompt_hash' => self::promptHash($systemPrompt),
            'schema_hash' => self::schemaHash($tools),
        ];
    }

    public static function promptHash(string $systemPrompt): string
    {
        return hash('sha256', $systemPrompt);
    }

    /**
     * @param  list<AgentTool>  $tools
     */
    public static function schemaHash(array $tools): string
    {
        $canonical = [];
        foreach ($tools as $tool) {
            $canonical[] = [
                'key' => $tool->key(),
                'description' => $tool->description(),
                'schema' => $tool->schema(),
            ];
        }

        try {
            $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new JsonException('Failed to canonicalise tool schemas: '.$e->getMessage(), 0, $e);
        }

        return hash('sha256', $json);
    }
}
