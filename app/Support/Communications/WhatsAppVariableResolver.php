<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Support\Automation\RunContext;

/**
 * Resolves WhatsApp template variable token_defaults / variable_tokens against a RunContext.
 */
final class WhatsAppVariableResolver
{
    /**
     * Map template variables[] (with token_default) to ordered resolved strings.
     *
     * @param  array<int, mixed>  $variables
     * @return list<string>
     */
    public static function resolveDefaults(array $variables, RunContext $context): array
    {
        $byIndex = [];
        foreach ($variables as $row) {
            if (! is_array($row)) {
                continue;
            }
            $index = (int) ($row['index'] ?? 0);
            if ($index < 1) {
                continue;
            }
            $token = $row['token_default'] ?? null;
            $byIndex[$index] = is_string($token) ? $token : null;
        }

        if ($byIndex === []) {
            return [];
        }

        ksort($byIndex);
        $out = [];
        foreach ($byIndex as $token) {
            $out[] = self::resolveToken($token, $context);
        }

        return $out;
    }

    /**
     * Map playbook variable_tokens {1: "contact.first_name", …} to ordered resolved strings.
     *
     * @param  array<int|string, mixed>  $variableTokens
     * @return list<string>
     */
    public static function resolveTokens(array $variableTokens, RunContext $context): array
    {
        $byIndex = [];
        foreach ($variableTokens as $key => $token) {
            $index = (int) $key;
            if ($index < 1) {
                continue;
            }
            $byIndex[$index] = is_string($token) ? $token : null;
        }

        if ($byIndex === []) {
            return [];
        }

        ksort($byIndex);
        $out = [];
        foreach ($byIndex as $token) {
            $out[] = self::resolveToken($token, $context);
        }

        return $out;
    }

    private static function resolveToken(?string $token, RunContext $context): string
    {
        if ($token === null) {
            return '';
        }

        $path = trim($token);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '{{') && str_ends_with($path, '}}')) {
            $path = trim(substr($path, 2, -2));
        }

        $resolved = $context->get($path);
        if ($resolved === null) {
            return '';
        }

        if (is_bool($resolved)) {
            return $resolved ? 'true' : 'false';
        }

        if (is_array($resolved)) {
            return json_encode($resolved) ?: '';
        }

        return (string) $resolved;
    }
}
