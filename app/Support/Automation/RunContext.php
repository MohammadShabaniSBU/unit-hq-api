<?php

declare(strict_types=1);

namespace App\Support\Automation;

/**
 * In-memory bag for token resolution: trigger payload + step outputs + subject bag.
 */
final class RunContext
{
    /** @var array<string, array<string, mixed>> */
    private array $stepOutputs;

    /**
     * @param  array<string, mixed>  $triggerPayload
     * @param  array<string, array<string, mixed>>  $stepOutputs  node_key => output
     * @param  array<string, mixed>  $subjectBag  friendly roots (contact, contract, …)
     */
    public function __construct(
        public readonly array $triggerPayload = [],
        array $stepOutputs = [],
        private readonly mixed $subjectId = null,
        private readonly array $subjectBag = [],
    ) {
        $this->stepOutputs = $stepOutputs;
    }

    /** @param  array<string, mixed>  $output */
    public function putStepOutput(string $nodeKey, array $output): void
    {
        $this->stepOutputs[$nodeKey] = $output;
    }

    /** @return array<string, array<string, mixed>> */
    public function stepOutputs(): array
    {
        return $this->stepOutputs;
    }

    public function triggerSubjectId(): mixed
    {
        return $this->subjectId;
    }

    public function get(string $path): mixed
    {
        $parts = explode('.', $path);
        if ($parts === []) {
            return null;
        }

        if ($parts[0] === 'steps' && isset($parts[1])) {
            $nodeKey = $parts[1];
            $rest = array_slice($parts, 2);
            $value = $this->stepOutputs[$nodeKey] ?? null;

            return $this->dig($value, $rest);
        }

        if ($parts[0] === 'trigger') {
            return $this->dig($this->triggerPayload, array_slice($parts, 1));
        }

        if (array_key_exists($parts[0], $this->subjectBag)) {
            return $this->dig($this->subjectBag, $parts);
        }

        return $this->dig($this->triggerPayload, $parts);
    }

    /**
     * @param  list<string>  $parts
     */
    private function dig(mixed $value, array $parts): mixed
    {
        foreach ($parts as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }
}
