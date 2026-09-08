<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Enums\AutopayAttemptStatus;
use App\Models\AutopayAttempt;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\Employee;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\Task;
use App\Support\Communications\Channel;
use App\Support\Communications\WhatsAppWindow;
use App\Support\Delinquency\DelinquencyState;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoPipeline;
use Database\Seeders\Demo\DemoRbacGrants;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\BeaTorres;
use Database\Seeders\Demo\Journeys\GraceLin;
use Database\Seeders\Demo\Journeys\OmarHaddad;
use Database\Seeders\Demo\Journeys\TheKellys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Compact demo world: 10 days, MAD-01 + MAD-02, cast only.
 */
class DemoCompactSeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DemoClock needs afterCommit hooks; do not wrap the pipeline in a transaction.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        DemoWorld::setCurrent(null);
        CastExecutor::resetWindow();

        parent::tearDown();
    }

    public function test_compact_pipeline_is_two_sites_and_ten_days(): void
    {
        $result = DemoPipeline::run($this->app, withCrowd: false, compact: true);

        $this->assertTrue(CastExecutor::isCompact());
        $this->assertSame(10, $result['days']);
        $this->assertSame(0, $result['crowd_count']);
        $this->assertSame('2025-06-10', CastExecutor::simEnd());

        $codes = Site::query()->orderBy('code')->pluck('code')->all();
        $this->assertSame(['MAD-01', 'MAD-02'], $codes);

        $lucia = Contact::query()
            ->where('first_name', 'Lucía')
            ->where('last_name', 'Ferrer')
            ->first();
        $this->assertNotNull($lucia, 'Lucía Ferrer should exist after a compact seed');

        $this->assertTrue(
            Employee::query()->where('email', 'agent-mad@example.com')->exists(),
        );
        $this->assertTrue(
            Employee::query()->where('email', 'agent-norte@example.com')->exists(),
        );
        $this->assertFalse(
            Employee::query()->where('email', 'agent-sur@example.com')->exists(),
            'agent-sur must not be pinned onto MAD-01 in compact',
        );
        $this->assertFalse(
            Employee::query()->where('email', 'sm-mad-03@example.com')->exists(),
        );

        DemoRbacGrants::verifyOrFail();

        $this->assertAgentMadCannotSeeSofia();
        $this->assertLuciaHasOpenDelinquency($lucia);
        $this->assertAnaHasNoOpenDelinquency();
        $this->assertPilarWhatsAppWindowIsOpen();

        $world = $result['world'];
        OmarHaddad::assertEndState($world);
        TheKellys::assertEndState($world);
        GraceLin::assertEndState($world);
        BeaTorres::assertEndState($world);

        $this->assertBeaTorresEmailSearchFindsThread();
        $this->assertGraciaLinTaskIsSearchable();
    }

    private function assertAgentMadCannotSeeSofia(): void
    {
        $agent = Employee::query()->where('email', 'agent-mad@example.com')->firstOrFail();
        Sanctum::actingAs($agent);

        $emails = collect($this->getJson('/api/contacts?per_page=100')->assertOk()->json('data'))
            ->pluck('email')
            ->all();

        $this->assertNotContains(
            'sofia.marin@demo.keevaris.test',
            $emails,
            'MAD-01 leasing agent must not see Sofía Marín (MAD-02)',
        );
    }

    private function assertLuciaHasOpenDelinquency(Contact $lucia): void
    {
        $contract = Contract::query()->where('contact_id', $lucia->id)->firstOrFail();

        $this->assertTrue(
            Delinquency::query()->where('contract_id', $contract->id)->open()->exists(),
            'Lucía should have an open delinquency after compact seed',
        );
        $this->assertTrue(
            bccomp(DelinquencyState::netOverdueAmount($contract->fresh(['charges.allocations'])), '0', 2) > 0,
            'Lucía should still owe',
        );
    }

    private function assertAnaHasNoOpenDelinquency(): void
    {
        $ana = Contact::query()->where('email', 'ana.coloma@demo.keevaris.test')->firstOrFail();
        $contract = Contract::query()->where('contact_id', $ana->id)->firstOrFail();

        $this->assertFalse(
            Delinquency::query()->where('contract_id', $contract->id)->open()->exists(),
            'Ana should not have an open delinquency case',
        );
        $this->assertGreaterThanOrEqual(
            2,
            AutopayAttempt::query()
                ->where('contract_id', $contract->id)
                ->where('status', AutopayAttemptStatus::Failed)
                ->where('decline_code', 'insufficient_funds')
                ->count(),
        );
    }

    private function assertPilarWhatsAppWindowIsOpen(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        $pilar = Contact::query()->where('email', 'pilar.santos@demo.keevaris.test')->firstOrFail();
        $thread = MessageThread::query()
            ->where('contact_id', $pilar->id)
            ->where('channel', Channel::Whatsapp)
            ->firstOrFail();

        $thread->refresh();
        $this->assertTrue(
            $thread->last_inbound_at->gt(now()->subHours(24)),
            'Pilar last_inbound_at must be within the live 24h window, not sim-end',
        );
        $this->assertTrue(WhatsAppWindow::isOpen($thread));

        $ops = Employee::query()->where('email', 'ops@example.com')->firstOrFail();
        Sanctum::actingAs($ops);
        $this->getJson("/api/inbox/threads/{$thread->id}/compose-context")
            ->assertOk()
            ->assertJsonPath('data.whatsapp_window.open', true);
    }

    private function assertBeaTorresEmailSearchFindsThread(): void
    {
        $ops = Employee::query()->where('email', 'ops@example.com')->firstOrFail();
        Sanctum::actingAs($ops);

        $threads = $this->getJson('/api/inbox/threads?channel=email&q='.rawurlencode('Bea Torres'))
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($threads, 'Bea Torres should have an email thread');
        $this->assertSame('Bea Torres', $threads[0]['contact']['name'] ?? null);
    }

    private function assertGraciaLinTaskIsSearchable(): void
    {
        $this->assertTrue(
            Task::query()->search('Gracia Lin')->exists(),
            'A lead-chase task for Gracia Lin should be findable by full name',
        );
    }
}
