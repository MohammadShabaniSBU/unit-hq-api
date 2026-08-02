<?php

declare(strict_types=1);

namespace App\Support\Automation;

/**
 * Resolves {{steps.<node_key>.<field>}} / {{trigger.*}} templates against RunContext.
 */
final class TokenResolver
{
    public const PREVIEW_MISSING_PREFIX = '⟦missing:';

    public const PREVIEW_MISSING_SUFFIX = '⟧';

    public static function resolve(string $template, RunContext $context): string
    {
        return self::resolveCollectingWarnings($template, $context)['value'];
    }

    /**
     * @return array{value: string, warnings: list<string>}
     */
    public static function resolveCollectingWarnings(
        string $template,
        RunContext $context,
        bool $previewMarkers = false,
    ): array {
        $warnings = [];

        $value = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $matches) use ($context, $previewMarkers, &$warnings): string {
                $path = $matches[1];
                $resolved = $context->get($path);

                if ($resolved === null) {
                    if (! in_array($path, $warnings, true)) {
                        $warnings[] = $path;
                    }

                    return $previewMarkers
                        ? self::PREVIEW_MISSING_PREFIX.$path.self::PREVIEW_MISSING_SUFFIX
                        : '';
                }

                if (is_bool($resolved)) {
                    return $resolved ? 'true' : 'false';
                }

                if (is_array($resolved)) {
                    return json_encode($resolved) ?: '';
                }

                return (string) $resolved;
            },
            $template,
        );

        return ['value' => $value, 'warnings' => $warnings];
    }

    /**
     * Resolve a ValueSource-shaped config value: { kind: static|dynamic, value|expression }.
     */
    public static function resolveValueSource(mixed $source, RunContext $context): mixed
    {
        if (! is_array($source)) {
            return $source;
        }

        $kind = $source['kind'] ?? 'static';

        if ($kind === 'dynamic') {
            $expression = (string) ($source['expression'] ?? '');
            $inner = trim($expression);
            if (str_starts_with($inner, '{{') && str_ends_with($inner, '}}')) {
                $path = trim(substr($inner, 2, -2));

                return $context->get($path);
            }

            return self::resolve($expression, $context);
        }

        return $source['value'] ?? null;
    }

    /**
     * Resolve a TargetRecord config into a subject id.
     *
     * @param  array<string, mixed>  $config
     */
    public static function resolveTargetRecord(array $config, RunContext $context): mixed
    {
        $mode = (string) ($config['mode'] ?? '');

        return match ($mode) {
            'trigger_subject' => $context->triggerSubjectId(),
            'step_output' => $context->get(
                'steps.'.($config['nodeKey'] ?? $config['node_key'] ?? '').'.'.($config['field'] ?? 'subject_id'),
            ),
            'static' => $config['id'] ?? null,
            'expression' => self::resolveExpressionTemplate(
                (string) ($config['template'] ?? ''),
                $context,
            ),
            default => null,
        };
    }

    private static function resolveExpressionTemplate(string $template, RunContext $context): mixed
    {
        $inner = trim($template);
        if ($inner === '') {
            return null;
        }

        if (str_starts_with($inner, '{{') && str_ends_with($inner, '}}')) {
            $path = trim(substr($inner, 2, -2));

            return $context->get($path);
        }

        $resolved = self::resolve($template, $context);

        return $resolved === '' ? null : $resolved;
    }

    /**
     * Normalize legacy update_object target fields into a TargetRecord array.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalizeTargetRecordConfig(array $config): array
    {
        $existing = $config['targetRecord'] ?? $config['target_record'] ?? null;
        if (is_array($existing) && isset($existing['mode'])) {
            return [
                'mode' => $existing['mode'],
                'nodeKey' => $existing['nodeKey'] ?? $existing['node_key'] ?? null,
                'field' => $existing['field'] ?? 'subject_id',
                'objectType' => $existing['objectType'] ?? $existing['object_type'] ?? ($config['objectType'] ?? $config['object_type'] ?? null),
                'id' => $existing['id'] ?? null,
                'template' => $existing['template'] ?? null,
            ];
        }

        $source = (string) ($config['targetSource'] ?? $config['target_source'] ?? 'trigger_object');
        $objectType = (string) ($config['objectType'] ?? $config['object_type'] ?? 'contact');

        if ($source === 'static_id') {
            $id = $config['staticId'] ?? $config['targetId'] ?? $config['target_id'] ?? null;

            return [
                'mode' => 'static',
                'objectType' => $objectType,
                'id' => $id,
            ];
        }

        if ($source === 'dynamic') {
            $template = (string) ($config['dynamicExpression'] ?? '');
            $targetId = $config['targetId'] ?? $config['target_id'] ?? null;
            if ($template === '' && is_array($targetId)) {
                $template = (string) ($targetId['expression'] ?? $targetId['value'] ?? '');
            } elseif ($template === '' && is_string($targetId)) {
                $template = $targetId;
            }

            return [
                'mode' => 'expression',
                'template' => $template,
            ];
        }

        return ['mode' => 'trigger_subject'];
    }
}
