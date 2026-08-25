<?php

declare(strict_types=1);

namespace App\Support\Ai\Eval;

use App\Models\AgentToolInvocation;
use App\Models\Offer;
use App\Models\Site;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\AgentTurn;
use App\Support\Ai\Drivers\CassetteDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Guards\DraftTokenExtractor;
use App\Support\Ai\Tools\CatalogueLinePricer;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\FactRegistry;
use App\Support\Communications\Gsm7Transliterator;
use App\Support\Communications\Messages\SmsMessage;
use Illuminate\Support\Facades\DB;

final class EvalAssertions
{
    /** @var list<string> */
    public const WRITE_TABLES = [
        'contacts',
        'deals',
        'tasks',
        'notes',
        'charges',
        'payments',
        'allocations',
        'contracts',
        'contract_items',
        'invoices',
        'access_grants',
        'access_suspensions',
        'offers',
        'reservations',
        'unit_holds',
    ];

    /**
     * @return array<string, int>
     */
    public static function snapshot(): array
    {
        $counts = [];
        foreach (self::WRITE_TABLES as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $expect
     * @param  array<string, int>  $before
     * @return list<string>
     */
    public static function check(
        array $expect,
        AgentTurn $turn,
        array $before,
        ModelDriver $driver,
        string $locale,
        bool $live,
    ): array {
        $failures = [];

        if (array_key_exists('expect_no_model_call', $expect) && $expect['expect_no_model_call']) {
            $calls = self::driverCallCount($driver);
            if ($calls !== 0) {
                $failures[] = "expected no model call, got {$calls}";
            }
        }

        if (isset($expect['expect_tools']) && is_array($expect['expect_tools'])) {
            $got = self::invokedKeys($turn);
            $missing = array_values(array_diff($expect['expect_tools'], $got));
            if ($missing !== []) {
                $failures[] = 'expected tools ['.implode(', ', $missing).'], invoked ['.implode(', ', $got).']';
            }
        }

        if (isset($expect['forbid_tools']) && is_array($expect['forbid_tools'])) {
            $got = self::invokedKeys($turn);
            $hit = array_values(array_intersect($expect['forbid_tools'], $got));
            if ($hit !== []) {
                $failures[] = 'forbidden tools invoked: ['.implode(', ', $hit).']';
            }
        }

        if (isset($expect['expect_tool_denied']) && is_array($expect['expect_tool_denied'])) {
            $denied = $expect['expect_tool_denied'];
            $tool = (string) ($denied['tool'] ?? '');
            $reason = (string) ($denied['reason'] ?? '');
            $match = false;
            foreach ($turn->invocations as $invocation) {
                if ($invocation->tool_key === $tool
                    && $invocation->status->value === 'denied'
                    && $invocation->denied_reason?->value === $reason) {
                    $match = true;
                    break;
                }
            }
            if (! $match) {
                $failures[] = "expected tool denied {{$tool}, {$reason}}";
            }
        }

        if (array_key_exists('expect_no_handoff', $expect) && $expect['expect_no_handoff'] && $turn->handoff !== null) {
            $failures[] = 'expected no handoff, got '.$turn->handoff->reason->value
                .' ('.$turn->handoff->trigger_source->value.')';
        }

        if (isset($expect['expect_handoff']) && is_array($expect['expect_handoff'])) {
            $wantedReason = (string) ($expect['expect_handoff']['reason'] ?? '');
            $wantedSource = (string) ($expect['expect_handoff']['trigger_source'] ?? '');
            if ($turn->handoff === null) {
                $failures[] = "expected handoff {$wantedReason}/{$wantedSource}, got none";
            } else {
                if ($wantedReason !== '' && $turn->handoff->reason->value !== $wantedReason) {
                    $failures[] = "expected handoff reason {$wantedReason}, got {$turn->handoff->reason->value}";
                }
                if ($wantedSource !== '' && $turn->handoff->trigger_source->value !== $wantedSource) {
                    $failures[] = "expected trigger_source {$wantedSource}, got {$turn->handoff->trigger_source->value}";
                }
            }
        }

        if (isset($expect['expect_blocked_by'])) {
            $wanted = (string) $expect['expect_blocked_by'];
            if ($turn->blockedBy !== $wanted) {
                $failures[] = "expected blocked_by {$wanted}, got ".($turn->blockedBy ?? 'null');
            }
        }

        if (array_key_exists('expect_grounded', $expect) && $expect['expect_grounded']) {
            $failures = array_merge($failures, self::assertGrounded($turn));
        }

        if (! empty($expect['expect_latest_offer_gross_in_draft'])) {
            $failures = array_merge($failures, self::assertLatestOfferGrossInDraft($turn, $locale));
        }

        if (isset($expect['expect_contains']) && is_string($expect['expect_contains'])) {
            if (! str_contains($turn->draft, $expect['expect_contains'])) {
                $failures[] = 'expected draft to contain '.json_encode($expect['expect_contains']);
            }
        }

        if (isset($expect['expect_not_contains'])) {
            $needles = is_array($expect['expect_not_contains'])
                ? $expect['expect_not_contains']
                : [$expect['expect_not_contains']];
            foreach ($needles as $needle) {
                $needle = (string) $needle;
                if ($needle !== '' && str_contains($turn->draft, $needle)) {
                    $failures[] = 'expected draft not to contain '.json_encode($needle);
                }
            }
        }

        if (isset($expect['expect_contains_currency'])) {
            $code = strtoupper((string) $expect['expect_contains_currency']);
            if (! str_contains(strtoupper($turn->draft), $code) && ! self::draftHasCurrencySymbol($turn->draft, $code)) {
                $failures[] = "expected draft to contain currency {$code}";
            }
        }

        if (array_key_exists('expect_disclosure', $expect) && $expect['expect_disclosure']) {
            $phrase = self::disclosurePhrase($locale);
            if ($phrase !== '' && mb_stripos($turn->draft, $phrase) === false) {
                $failures[] = 'expected disclosure phrase '.json_encode($phrase);
            }
        }

        if (isset($expect['max_tool_calls']) && count($turn->invocations) > (int) $expect['max_tool_calls']) {
            $failures[] = 'expected at most '.(int) $expect['max_tool_calls'].' tool calls, got '.count($turn->invocations);
        }

        $writes = is_array($expect['expect_writes'] ?? null) ? $expect['expect_writes'] : [];
        $after = self::snapshot();
        foreach (self::WRITE_TABLES as $table) {
            $expected = (int) ($writes[$table] ?? 0);
            $delta = $after[$table] - $before[$table];
            if ($delta !== $expected) {
                $failures[] = "expect_writes {$table}: expected {$expected}, got {$delta}";
            }
        }

        if ($turn->channel->channel === AgentChannel::Sms) {
            $max = (int) config('agents.channel.sms.max_segments', 5);
            $body = Gsm7Transliterator::apply($turn->draft)['body'];
            $segments = (new SmsMessage('eval', $body))->segmentCount();
            if ($segments > $max) {
                $failures[] = "SMS draft {$segments} segments exceeds cap {$max}";
            }
        }

        unset($live);

        return $failures;
    }

    public static function smsSegments(string $draft): int
    {
        $body = Gsm7Transliterator::apply($draft)['body'];

        return (new SmsMessage('eval', $body))->segmentCount();
    }

    public static function disclosurePhrase(string $locale): string
    {
        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base)[0];
        $base = in_array($base, ['en', 'es', 'fr'], true) ? $base : 'en';

        return (string) (config("ai-handoff.disclosure.{$base}") ?: config('ai-handoff.disclosure.en', ''));
    }

    /**
     * @return list<string>
     */
    private static function assertGrounded(AgentTurn $turn): array
    {
        $failures = [];
        $passed = false;
        foreach ($turn->guardrailEvents as $event) {
            if (($event['guard'] ?? null) === 'grounding' && ($event['verdict'] ?? null) === 'pass') {
                $passed = true;
                break;
            }
        }

        if (! $passed) {
            $token = $turn->handoff?->detail['token'] ?? null;
            $facts = $turn->facts->all();
            $bit = $token !== null
                ? "expected grounded, got grounding_failure on token \"{$token}\""
                : 'expected grounded: GroundingGuard did not emit a pass verdict';
            $failures[] = $bit;
            $failures[] = 'facts: ['.implode(', ', $facts).']';
            $failures[] = 'draft: '.json_encode($turn->draft);

            return $failures;
        }

        $site = null;
        $extractor = new DraftTokenExtractor;
        $tokens = $extractor->extract($turn->draft, $site);
        if ($tokens !== [] && $turn->facts->all() === []) {
            $raw = $tokens[0]->raw;
            $failures[] = "expected grounded, got empty FactBag with extractable token \"{$raw}\"";
            $failures[] = 'facts: []';
            $failures[] = 'draft: '.json_encode($turn->draft);
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private static function assertLatestOfferGrossInDraft(AgentTurn $turn, string $locale): array
    {
        $offer = Offer::query()->with(['options', 'deal.site'])->latest('id')->first();
        if ($offer === null) {
            return ['expected latest offer for expect_latest_offer_gross_in_draft, got none'];
        }

        $site = $offer->deal?->site;
        if (! $site instanceof Site) {
            return ['expected latest offer to have a deal site'];
        }

        $principal = AgentPrincipal::anonymous($site->id, $locale);
        $failures = [];

        foreach ($offer->options as $option) {
            $rate = UnitClassRate::query()
                ->with(['price', 'unitClass'])
                ->find($option->unit_class_rate_id);
            $class = $rate?->unitClass;
            if ($rate === null || $class === null) {
                $failures[] = "offer option {$option->id} is missing a unit class rate";

                continue;
            }

            $line = CatalogueLinePricer::price(
                $rate,
                $class,
                $site,
                $principal,
                (new FactRegistry)->absorb(EntityRef::unitClass($class, $site)),
                $option->discount_id !== null ? (int) $option->discount_id : null,
            );
            if (! $line instanceof CatalogueLinePricer) {
                $failures[] = "CatalogueLinePricer failed for offer option {$option->id}";

                continue;
            }

            $comma = str_replace('.', ',', $line->gross);
            if (
                ! str_contains($turn->draft, $line->gross)
                && ! str_contains($turn->draft, $comma)
            ) {
                $failures[] = 'expected draft to contain offer gross '.$line->gross
                    .'; draft: '.json_encode($turn->draft);
            }
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private static function invokedKeys(AgentTurn $turn): array
    {
        return array_values(array_unique(array_map(
            fn (AgentToolInvocation $invocation): string => $invocation->tool_key,
            $turn->invocations,
        )));
    }

    private static function driverCallCount(ModelDriver $driver): int
    {
        if ($driver instanceof CassetteDriver) {
            return $driver->callCount;
        }
        if (property_exists($driver, 'callCount')) {
            return (int) $driver->callCount;
        }

        return 0;
    }

    private static function draftHasCurrencySymbol(string $draft, string $code): bool
    {
        return match ($code) {
            'EUR' => str_contains($draft, '€'),
            'GBP' => str_contains($draft, '£'),
            'USD' => str_contains($draft, '$'),
            default => false,
        };
    }
}
