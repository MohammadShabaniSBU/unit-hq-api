<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\PendingActionCommit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ToolDispatcherProductionCtxTest extends TestCase
{
    #[Test]
    public function every_production_dispatch_caller_passes_a_context(): void
    {
        $callers = $this->dispatchCallers(app_path());

        $this->assertArrayHasKey(
            app_path('Support/Ai/AgentRuntime.php'),
            $callers,
            'AgentRuntime must call ToolDispatcher::dispatch.',
        );

        foreach ($callers as $path => $calls) {
            foreach ($calls as $index => $args) {
                $this->assertGreaterThanOrEqual(
                    5,
                    count($args),
                    "{$path} dispatch #{$index} is missing AgentContext (5th argument).",
                );
                $this->assertNotSame(
                    'null',
                    $args[4],
                    "{$path} dispatch #{$index} passes a null AgentContext — test-only. Production must pass a ctx.",
                );
            }
        }

        $pending = (string) file_get_contents((new \ReflectionClass(PendingActionCommit::class))->getFileName());
        $this->assertStringNotContainsString(
            'ToolDispatcher',
            $pending,
            'PendingActionCommit started calling ToolDispatcher; it must pass a non-null AgentContext.',
        );
    }

    /**
     * @return array<string, list<list<string>>>
     */
    private function dispatchCallers(string $root): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $source = (string) file_get_contents($path);
            if (! str_contains($source, 'ToolDispatcher') && ! str_contains($source, 'dispatcher->dispatch')) {
                continue;
            }

            $calls = $this->extractDispatchArgs($source);
            if ($calls !== []) {
                $found[$path] = $calls;
            }
        }

        return $found;
    }

    /**
     * @return list<list<string>>
     */
    private function extractDispatchArgs(string $source): array
    {
        $calls = [];
        $offset = 0;
        while (true) {
            $pos = strpos($source, '->dispatch(', $offset);
            if ($pos === false) {
                break;
            }
            $open = $pos + strlen('->dispatch(');
            $depth = 1;
            $i = $open;
            $len = strlen($source);
            while ($i < $len && $depth > 0) {
                $char = $source[$i];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
                $i++;
            }
            $inside = substr($source, $open, $i - $open - 1);
            $args = $this->splitArgs($inside);
            // ToolDispatcher::dispatch has 4–5 args (definition, principal, key, arguments, ctx).
            // Skip unrelated ->dispatch( helpers (Aircall injectors, etc.).
            if (count($args) >= 4) {
                $calls[] = array_map(trim(...), $args);
            }
            $offset = $i;
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function splitArgs(string $inside): array
    {
        $args = [];
        $current = '';
        $depth = 0;
        $len = strlen($inside);
        for ($i = 0; $i < $len; $i++) {
            $char = $inside[$i];
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $args[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $args[] = $current;
        }

        return $args;
    }
}
