<?php

declare(strict_types=1);

namespace App\Support\Ai\Eval;

use App\Support\Ai\Drivers\CassetteDriver;
use App\Support\Ai\Drivers\ModelResponse;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class CassetteStore
{
    public function __construct(private readonly string $root) {}

    public static function defaultRoot(): string
    {
        return base_path('tests/Fixtures/agents');
    }

    public function pathFor(string $fixtureId): string
    {
        [$agent, $slug] = $this->splitId($fixtureId);

        return $this->root.DIRECTORY_SEPARATOR.$agent.DIRECTORY_SEPARATOR.'cassettes'.DIRECTORY_SEPARATOR.$slug.'.json';
    }

    public function exists(string $fixtureId): bool
    {
        return is_file($this->pathFor($fixtureId));
    }

    /**
     * @param  array<string, string>  $replacements
     * @return array{agent: string, fixture: string, turn_index: int, prompt_hash: string, schema_hash: string, responses: list<array<string, mixed>>}
     */
    public function loadRaw(string $fixtureId): array
    {
        $path = $this->pathFor($fixtureId);
        if (! is_file($path)) {
            throw new RuntimeException("Cassette missing for [{$fixtureId}] at {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Cassette [{$fixtureId}] is not valid JSON.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, string>  $replacements
     * @return array{prompt_hash: string, schema_hash: string, responses: list<ModelResponse>}
     */
    public function load(string $fixtureId, array $replacements = []): array
    {
        $raw = $this->loadRaw($fixtureId);
        $rows = $raw['responses'] ?? [];
        if (! is_array($rows)) {
            throw new RuntimeException("Cassette [{$fixtureId}] has no responses list.");
        }

        $responses = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $interpolated = self::interpolate($row, $replacements);
            $responses[] = CassetteDriver::responseFromArray($interpolated);
        }

        return [
            'prompt_hash' => (string) ($raw['prompt_hash'] ?? ''),
            'schema_hash' => (string) ($raw['schema_hash'] ?? ''),
            'responses' => $responses,
        ];
    }

    /**
     * @param  list<ModelResponse>  $responses
     */
    public function write(
        string $fixtureId,
        int $turnIndex,
        string $promptHash,
        string $schemaHash,
        array $responses,
    ): void {
        $path = $this->pathFor($fixtureId);
        File::ensureDirectoryExists(dirname($path));

        [$agent] = $this->splitId($fixtureId);
        $payload = [
            'agent' => $agent,
            'fixture' => $fixtureId,
            'turn_index' => $turnIndex,
            'prompt_hash' => $promptHash,
            'schema_hash' => $schemaHash,
            'responses' => array_map(
                fn (ModelResponse $response): array => CassetteDriver::responseToArray($response),
                $responses,
            ),
        ];

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /**
     * Rewrite hashes, leave responses byte-identical in structure (re-encoded).
     *
     * @return array{path: string, responses_unchanged: bool}
     */
    public function seal(string $fixtureId, string $promptHash, string $schemaHash): array
    {
        $raw = $this->loadRaw($fixtureId);
        $before = json_encode($raw['responses'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw['prompt_hash'] = $promptHash;
        $raw['schema_hash'] = $schemaHash;
        $after = json_encode($raw['responses'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $path = $this->pathFor($fixtureId);
        file_put_contents(
            $path,
            json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        return [
            'path' => $path,
            'responses_unchanged' => $before === $after,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitId(string $fixtureId): array
    {
        $parts = explode('/', $fixtureId, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException("Fixture id [{$fixtureId}] must be {agent}/{slug}.");
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    public static function interpolate(mixed $value, array $replacements): mixed
    {
        if (is_string($value)) {
            $replaced = strtr($value, $replacements);
            if ($replaced !== $value && preg_match('/^-?\d+$/', $replaced) === 1) {
                return (int) $replaced;
            }

            return $replaced;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::interpolate($item, $replacements);
            }

            return $out;
        }

        return $value;
    }
}
