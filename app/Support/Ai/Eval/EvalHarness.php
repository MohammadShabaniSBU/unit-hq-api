<?php

declare(strict_types=1);

namespace App\Support\Ai\Eval;

use App\Models\AgentConversation;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Drivers\CassetteDriver;
use App\Support\Ai\Drivers\LaravelAiDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Drivers\RecordingModelDriver;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\Ai\Tools\ToolRegistry;
use Carbon\Carbon;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EvalHarness
{
    public function __construct(
        private readonly Application $app,
        private readonly EvalFixtureLoader $loader = new EvalFixtureLoader,
    ) {}

    /**
     * @return array{report: EvalReport, sealed: int}
     */
    public function run(
        string $root,
        ?string $agent = null,
        ?string $filter = null,
        bool $live = false,
        bool $record = false,
        bool $seal = false,
    ): array {
        EvalWorld::freezeClock();
        $store = new CassetteStore($root);
        $report = new EvalReport;
        $sealed = 0;

        DB::beginTransaction();
        try {
            $world = EvalWorld::seed();
            $fixtures = $this->loader->load($root, $agent, $filter);

            foreach ($fixtures as $fixture) {
                if ($seal) {
                    $sealed += $this->sealFixture($world, $store, $fixture) ? 1 : 0;

                    continue;
                }

                $report->add($this->runFixture($world, $store, $fixture, $live, $record));
            }
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }

        return ['report' => $report, 'sealed' => $sealed];
    }

    private function sealFixture(EvalWorld $world, CassetteStore $store, EvalFixture $fixture): bool
    {
        if ($this->expectsNoModel($fixture)) {
            return false;
        }

        DB::beginTransaction();
        try {
            [$conversation, $principal, $ctx] = $this->openConversation($world, $fixture);
            $hashes = $this->hashes($ctx);
            $store->seal($fixture->id, $hashes['prompt_hash'], $hashes['schema_hash']);
            unset($conversation, $principal);
        } finally {
            DB::rollBack();
        }

        return true;
    }

    private function runFixture(
        EvalWorld $world,
        CassetteStore $store,
        EvalFixture $fixture,
        bool $live,
        bool $record,
    ): EvalCaseResult {
        DB::beginTransaction();
        try {
            return $this->runFixtureInner($world, $store, $fixture, $live, $record);
        } catch (EvalCassetteStaleException $e) {
            return $this->failed($fixture, ['stale cassette: '.$e->getMessage()]);
        } catch (Throwable $e) {
            return $this->failed($fixture, [$e->getMessage()]);
        } finally {
            DB::rollBack();
        }
    }

    private function runFixtureInner(
        EvalWorld $world,
        CassetteStore $store,
        EvalFixture $fixture,
        bool $live,
        bool $record,
    ): EvalCaseResult {
        [$conversation, $principal, $ctx] = $this->openConversation($world, $fixture);
        $hashes = $this->hashes($ctx);
        $noModel = $this->expectsNoModel($fixture);
        $replacements = $world->replacements();

        $driver = $this->bindDriver($live, $record, $noModel, $store, $fixture, $hashes, $replacements);

        $failures = [];
        $toolCalls = 0;
        $tokens = 0;
        $blockedUnexpectedly = false;
        $lastDraft = null;
        $lastTurn = null;

        foreach ($fixture->turns as $index => $turnExpect) {
            $before = EvalAssertions::snapshot();
            $input = (string) ($turnExpect['input'] ?? '');

            $turn = $this->app->make(AgentRuntime::class)->turn(
                $conversation->fresh(),
                $principal,
                $input,
            );
            $lastTurn = $turn;
            $lastDraft = $turn->draft;
            $toolCalls += count($turn->invocations);
            foreach ($turn->usageEvents as $usage) {
                $tokens += (int) $usage->input_tokens + (int) $usage->output_tokens;
            }

            $turnFailures = EvalAssertions::check(
                $turnExpect,
                $turn,
                $before,
                $driver,
                $fixture->locale,
                $live,
                $replacements,
            );
            $failures = array_merge($failures, $turnFailures);

            if ($turn->blockedBy !== null && ! isset($turnExpect['expect_blocked_by'])) {
                $blockedUnexpectedly = true;
            }

            if ($live && ($record || ! $store->exists($fixture->id)) && $driver instanceof RecordingModelDriver) {
                $store->write(
                    $fixture->id,
                    $index,
                    $hashes['prompt_hash'],
                    $hashes['schema_hash'],
                    $driver->recorded,
                );
            }
        }

        return new EvalCaseResult(
            $fixture->id,
            $fixture->agent,
            $failures === [],
            $failures,
            $toolCalls,
            $tokens,
            $blockedUnexpectedly,
            $fixture->liveOnly,
            $lastDraft,
            $fixture->channel === 'sms' && is_string($lastDraft)
                ? EvalAssertions::smsSegments($lastDraft)
                : null,
        );
    }

    /**
     * @param  array<string, string>  $replacements
     * @param  array{prompt_hash: string, schema_hash: string}  $hashes
     */
    private function bindDriver(
        bool $live,
        bool $record,
        bool $noModel,
        CassetteStore $store,
        EvalFixture $fixture,
        array $hashes,
        array $replacements,
    ): ModelDriver {
        if ($live) {
            $inner = $this->app->make(LaravelAiDriver::class);
            $driver = ($record || ! $store->exists($fixture->id))
                ? new RecordingModelDriver($inner)
                : $inner;
            $this->app->instance(ModelDriver::class, $driver);

            return $driver;
        }

        $cassette = new CassetteDriver;
        if (! $noModel) {
            $loaded = $store->load($fixture->id, $replacements);
            if ($loaded['prompt_hash'] !== $hashes['prompt_hash']
                || $loaded['schema_hash'] !== $hashes['schema_hash']) {
                $which = $loaded['prompt_hash'] !== $hashes['prompt_hash'] ? 'prompt_hash' : 'schema_hash';
                throw new EvalCassetteStaleException(
                    "stale cassette for [{$fixture->id}]: {$which} does not match current prompt/schema assembly. Run `php artisan agent:replay --seal` and review the diff.",
                );
            }
            $cassette->load($loaded['responses']);
        }

        $this->app->instance(ModelDriver::class, $cassette);

        return $cassette;
    }

    /**
     * @return array{0: AgentConversation, 1: AgentPrincipal, 2: AgentContext}
     */
    private function openConversation(EvalWorld $world, EvalFixture $fixture): array
    {
        $verification = VerificationLevel::from((string) ($fixture->principal['verification'] ?? 'anonymous'));
        $contactKey = $fixture->principal['contact'] ?? null;
        $contact = is_string($contactKey) ? $world->contact($contactKey) : null;
        $siteKey = array_key_exists('site', $fixture->principal)
            ? $fixture->principal['site']
            : 'madrid';
        $siteId = match (true) {
            $siteKey === null, $siteKey === 'none', $siteKey === '' => null,
            (string) $siteKey === 'london' => $world->london->id,
            (string) $siteKey === 'empty' => $world->empty->id,
            default => $world->madrid->id,
        };

        if ($verification === VerificationLevel::Anonymous) {
            $principal = AgentPrincipal::anonymous($siteId, $fixture->locale);
            $contactId = null;
        } elseif ($contact === null) {
            throw new \RuntimeException("Fixture [{$fixture->id}] needs principal.contact for verification {$verification->value}.");
        } elseif ($verification === VerificationLevel::ChannelAsserted) {
            $principal = AgentPrincipal::channelAsserted($contact->id, $siteId, $fixture->locale);
            $contactId = $contact->id;
        } else {
            $principal = AgentPrincipal::verified($contact->id, $siteId, $fixture->locale);
            $contactId = $contact->id;
        }

        $channel = AgentChannel::from($fixture->channel);
        $agent = $world->agent($fixture->agent);

        // write_policies applies to this fixture's conversation only and never
        // touches AiAgentSeeder. reservation-commit.yaml forces the mode S24-05
        // withheld from production — not the seeded default.
        foreach ($fixture->writePolicies as $toolKey => $mode) {
            $agent->writePolicies()->updateOrCreate(
                ['tool_key' => $toolKey],
                ['mode' => WritePolicyMode::from($mode)],
            );
        }
        $agent->unsetRelation('writePolicies');
        $agent->load('writePolicies');

        $conversation = AgentConversation::factory()->create([
            'ai_agent_id' => $agent->id,
            'audience' => AgentAudience::Customer,
            'origin' => AgentOrigin::Demo,
            'channel' => $channel,
            'employee_id' => null,
            'created_by_employee_id' => $world->operator->id,
            'contact_id' => $contactId,
            'site_id' => $siteId,
            'verification_level' => $verification,
            'state' => ConversationState::Active,
            'locale' => $fixture->locale,
        ]);

        $definition = $this->app->make(AgentRegistry::class)->get($fixture->agent);
        $ctx = new AgentContext(
            $principal,
            ChannelProfile::for($channel),
            $definition,
            $conversation,
            $agent,
        );

        return [$conversation, $principal, $ctx];
    }

    /**
     * @return array{prompt_hash: string, schema_hash: string}
     */
    private function hashes(AgentContext $ctx): array
    {
        $registry = $this->app->make(ToolRegistry::class);
        $tools = [];
        foreach ($ctx->definition->toolKeys() as $key) {
            $tools[] = $registry->get($key);
        }

        return CassetteKey::hashes($ctx->definition->systemPrompt($ctx), $tools);
    }

    private function expectsNoModel(EvalFixture $fixture): bool
    {
        foreach ($fixture->turns as $turn) {
            if (! empty($turn['expect_no_model_call'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $failures
     */
    private function failed(EvalFixture $fixture, array $failures): EvalCaseResult
    {
        return new EvalCaseResult(
            $fixture->id,
            $fixture->agent,
            false,
            $failures,
            0,
            0,
            false,
            $fixture->liveOnly,
            null,
            null,
        );
    }
}
