<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\Journey;
use Database\Seeders\Demo\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Per-persona isolation smoke: each journey runs alone on a bare stage, then
 * assertEndState(). Task 04 reuses the same assertion methods on the full seed.
 */
class PersonaSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DemoWorld::setCurrent(null);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: class-string<Journey>}>
     */
    public static function personaProvider(): array
    {
        $cases = [];
        foreach (CastExecutor::CAST as $class) {
            $cases[$class::handle()] = [$class];
        }

        return $cases;
    }

    /**
     * @param  class-string<Journey>  $class
     */
    #[DataProvider('personaProvider')]
    public function test_persona_reaches_end_state(string $class): void
    {
        Config::set('queue.default', 'sync');

        $world = new DemoWorld;
        DemoWorld::setCurrent($world);

        $seeder = new StageSeeder;
        $seeder->setContainer($this->app);
        $seeder->run();
        $world->hydrateFromDatabase();

        $start = CarbonImmutable::parse(CastExecutor::SIM_START)->startOfDay();
        $executor = new CastExecutor([$class]);
        $executor->runPersona($class, $world, $start);

        $class::assertEndState($world);
    }
}
