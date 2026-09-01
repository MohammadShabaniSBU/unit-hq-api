<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\Employee;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentReplayHarnessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_deliberately_wrong_fixture_fails(): void
    {
        $path = base_path('tests/Fixtures/agents/_harness/wrong-expect');
        $this->artisan('agent:replay', ['--path' => $path, '--seal' => true])->assertSuccessful();
        $this->artisan('agent:replay', ['--path' => $path])
            ->expectsOutputToContain('expected tools')
            ->assertFailed();
    }

    #[Test]
    public function a_stale_cassette_fails_loudly(): void
    {
        $path = base_path('tests/Fixtures/agents/_harness/stale-hash');
        $this->artisan('agent:replay', ['--path' => $path])
            ->expectsOutputToContain('stale')
            ->assertFailed();
    }

    #[Test]
    public function seal_rewrites_hashes_only(): void
    {
        $cassette = base_path('tests/Fixtures/agents/_harness/stale-hash/concierge/cassettes/stale-hash.json');
        $before = json_decode((string) file_get_contents($cassette), true);
        $this->assertSame('deadbeef', $before['prompt_hash']);
        $responsesBefore = json_encode($before['responses']);

        $this->artisan('agent:replay', [
            '--path' => base_path('tests/Fixtures/agents/_harness/stale-hash'),
            '--seal' => true,
        ])->assertSuccessful();

        $after = json_decode((string) file_get_contents($cassette), true);
        $this->assertNotSame('deadbeef', $after['prompt_hash']);
        $this->assertNotSame('deadbeef', $after['schema_hash']);
        $this->assertSame(64, strlen((string) $after['prompt_hash']));
        $this->assertSame($responsesBefore, json_encode($after['responses']));

        file_put_contents($cassette, json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    #[Test]
    public function vacuous_expect_grounded_fails(): void
    {
        $path = base_path('tests/Fixtures/agents/_harness/vacuous-grounded');
        $this->artisan('agent:replay', ['--path' => $path, '--seal' => true])->assertSuccessful();
        $this->artisan('agent:replay', ['--path' => $path])
            ->expectsOutputToContain('expected grounded')
            ->assertFailed();
    }

    #[Test]
    public function json_output_parses(): void
    {
        $this->assertSame(0, Artisan::call('agent:replay', [
            '--path' => base_path('tests/Fixtures/agents/_harness/json-ok'),
            '--json' => true,
        ]));

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertNotEmpty($decoded['results']);
        $this->assertArrayHasKey('id', $decoded['results'][0]);
        $this->assertArrayHasKey('passed', $decoded['results'][0]);
        $this->assertArrayHasKey('failures', $decoded['results'][0]);
        $this->assertTrue($decoded['results'][0]['passed']);
    }

    #[Test]
    public function seed_reuses_existing_default_tax_rate(): void
    {
        $employee = Employee::factory()->create();
        TaxRate::query()->create([
            'name' => 'VAT ES',
            'code' => 'vat',
            'rate' => '21.00',
            'jurisdiction' => 'ES',
            'is_default' => true,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'created_by' => $employee->id,
        ]);

        $this->artisan('agent:replay', [
            '--path' => base_path('tests/Fixtures/agents/_harness/json-ok'),
            '--json' => true,
        ])->assertSuccessful();
    }

    #[Test]
    public function no_model_call_fixtures_pass_without_cassettes(): void
    {
        $this->assertSame(0, Artisan::call('agent:replay', [
            '--filter' => 'no-model-call',
            '--json' => true,
        ]));

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertCount(9, $decoded['results']);
        foreach ($decoded['results'] as $result) {
            $this->assertTrue($result['passed'], $result['id'] ?? 'unknown');
        }
    }

    #[Test]
    public function cassette_suite_passes(): void
    {
        if (! is_dir(base_path('tests/Fixtures/agents/concierge/cassettes'))) {
            $this->markTestSkipped('Cassettes deleted by S27-01; re-recorded in the S27-04 follow-up.');
        }

        if (getenv('EVAL_WRITE_HASHES') === '1') {
            $this->artisan('agent:replay', ['--seal' => true])->assertSuccessful();
        }

        $this->artisan('agent:replay')->assertSuccessful();
    }
}
