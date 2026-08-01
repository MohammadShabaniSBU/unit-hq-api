<?php

declare(strict_types=1);

namespace Tests\Feature\Automation\Harness;

use App\Support\Automation\AutomationExecutor;
use PHPUnit\Framework\TestCase;
use Tests\Support\AutomationFixtureLoader;

/**
 * CI gate: every registered node handler must appear in ≥1 committed fixture.
 */
class HandlerCoverageTest extends TestCase
{
    public function test_every_handler_appears_in_a_fixture(): void
    {
        $fixtureTypes = AutomationFixtureLoader::allFixtureNodeTypes();
        $missing = [];

        foreach (array_keys(AutomationExecutor::handlers()) as $handlerType) {
            if (! in_array($handlerType, $fixtureTypes, true)) {
                $missing[] = $handlerType;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Handlers missing from tests/fixtures/automations: '.implode(', ', $missing),
        );
    }
}
