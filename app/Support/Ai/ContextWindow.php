<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Deal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\FactRegistry;
use App\Support\Ai\Tools\RefsRenderer;

/**
 * Pure trim of the message list sent to the model. Persistence is unchanged.
 */
final class ContextWindow
{
    public const SUMMARY_KEY = '_context_summary';

    public const QUOTES_KEY = '_retained_quotes';

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public static function build(array $messages, FactRegistry $registry, ?Deal $deal = null): ContextWindowResult
    {
        $recentTurns = max(1, (int) config('agents.context.recent_turns', 4));
        $maxHistoryChars = max(0, (int) config('agents.context.max_history_chars', 6_000));

        [$messages, $hadSummary, $priorQuotes] = self::stripPriorSummary($messages);

        $prompt = [];
        $rest = $messages;
        if ($rest !== [] && ($rest[0]['role'] ?? '') === 'system') {
            $prompt[] = array_shift($rest);
        }

        $windowStart = self::windowStart($rest, $recentTurns);
        $older = array_slice($rest, 0, $windowStart);
        $recent = array_slice($rest, $windowStart);

        $evictedQuotes = $priorQuotes;
        $dropped = 0;
        $olderKept = [];

        foreach ($older as $message) {
            $role = (string) ($message['role'] ?? '');
            if ($role === 'tool') {
                foreach ($message[self::QUOTES_KEY] ?? [] as $quote) {
                    if (is_array($quote)) {
                        $evictedQuotes[] = $quote;
                    }
                }
                $dropped++;

                continue;
            }

            if ($role === 'assistant') {
                $calls = $message['tool_calls'] ?? [];
                if (is_array($calls) && $calls !== []) {
                    unset($message['tool_calls']);
                }
                if (trim((string) ($message['content'] ?? '')) === '') {
                    $dropped++;

                    continue;
                }
            }

            $olderKept[] = $message;
        }

        $beforeTrim = count($olderKept);
        $olderKept = self::trimOlderText($olderKept, $maxHistoryChars);
        $dropped += $beforeTrim - count($olderKept);

        $out = $prompt;
        $summaryChars = 0;
        if ($dropped > 0 || $hadSummary) {
            $summary = self::summary($registry, $deal, $evictedQuotes);
            if ($summary !== '') {
                $summaryChars = mb_strlen($summary);
                $out[] = [
                    'role' => 'system',
                    'content' => $summary,
                    self::SUMMARY_KEY => true,
                    self::QUOTES_KEY => self::dedupeQuotes($evictedQuotes),
                ];
            }
        }

        foreach ([...$olderKept, ...$recent] as $message) {
            $out[] = $message;
        }

        return new ContextWindowResult(
            $out,
            count($out),
            $dropped,
            $summaryChars,
            self::estimatedTokens($out),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public static function withoutInternalKeys(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            unset($message[self::SUMMARY_KEY], $message[self::QUOTES_KEY]);
            $out[] = $message;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: list<array<string, mixed>>}
     */
    private static function stripPriorSummary(array $messages): array
    {
        $hadSummary = false;
        $quotes = [];
        $out = [];

        foreach ($messages as $message) {
            if (($message[self::SUMMARY_KEY] ?? false) === true) {
                $hadSummary = true;
                foreach ($message[self::QUOTES_KEY] ?? [] as $quote) {
                    if (is_array($quote)) {
                        $quotes[] = $quote;
                    }
                }

                continue;
            }

            $out[] = $message;
        }

        return [$out, $hadSummary, $quotes];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private static function windowStart(array $messages, int $recentTurns): int
    {
        $userIndexes = [];
        foreach ($messages as $i => $message) {
            if (($message['role'] ?? '') === 'user') {
                $userIndexes[] = $i;
            }
        }

        if (count($userIndexes) <= $recentTurns) {
            return 0;
        }

        return $userIndexes[count($userIndexes) - $recentTurns];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private static function trimOlderText(array $messages, int $maxChars): array
    {
        $total = 0;
        foreach ($messages as $message) {
            $total += mb_strlen((string) ($message['content'] ?? ''));
        }
        if ($total <= $maxChars) {
            return $messages;
        }

        $dropRole = static function (array $rows, string $role) use (&$total, $maxChars): array {
            $kept = [];
            foreach ($rows as $message) {
                if ($total > $maxChars && ($message['role'] ?? '') === $role) {
                    $total -= mb_strlen((string) ($message['content'] ?? ''));

                    continue;
                }
                $kept[] = $message;
            }

            return $kept;
        };

        $messages = $dropRole($messages, 'assistant');
        if ($total > $maxChars) {
            $messages = $dropRole($messages, 'user');
        }

        return array_values($messages);
    }

    /**
     * @param  list<array<string, mixed>>  $quotes
     */
    private static function summary(FactRegistry $registry, ?Deal $deal, array $quotes): string
    {
        $parts = [];

        $earlier = self::earlierSegment($registry);
        if ($earlier !== '') {
            $parts[] = 'Earlier in this conversation: '.$earlier;
        }

        $needs = self::needsSegment($deal);
        if ($needs !== '') {
            $parts[] = $needs;
        }

        $prices = self::pricesSegment($quotes);
        if ($prices !== '') {
            $parts[] = $prices;
        }

        $refs = RefsRenderer::render($registry->refs());
        if ($refs !== '') {
            $parts[] = $refs;
        }

        return implode("\n", $parts);
    }

    private static function earlierSegment(FactRegistry $registry): string
    {
        /** @var array<string, list<EntityRef>> $byType */
        $byType = [];
        foreach ($registry->refs() as $ref) {
            $byType[$ref->type->value][] = $ref;
        }

        $bits = [];
        if (isset($byType[EntityType::Site->value])) {
            $labels = array_map(static fn (EntityRef $ref): string => $ref->label, $byType[EntityType::Site->value]);
            $bits[] = 'site '.implode(', ', $labels);
        }
        if (isset($byType[EntityType::UnitClass->value])) {
            $labels = array_map(static fn (EntityRef $ref): string => $ref->label, $byType[EntityType::UnitClass->value]);
            $bits[] = 'classes discussed: '.implode(', ', $labels);
        }

        $created = [];
        foreach ([EntityType::Contact, EntityType::Deal, EntityType::Offer, EntityType::Reservation] as $type) {
            foreach ($byType[$type->value] ?? [] as $ref) {
                $created[] = $ref->type->value.' '.$ref->id;
            }
        }
        if ($created !== []) {
            $bits[] = 'created '.implode(', ', $created);
        }

        return implode('; ', $bits);
    }

    private static function needsSegment(?Deal $deal): string
    {
        if ($deal === null) {
            return '';
        }

        $bits = [];
        $name = trim((string) ($deal->contact?->first_name ?? ''));
        if ($name !== '') {
            $bits[] = $name;
        }
        if ($deal->site !== null && $deal->site->name !== '') {
            $bits[] = 'site '.$deal->site->name;
        }
        if ($deal->expected_move_in !== null) {
            $bits[] = 'move-in '.$deal->expected_move_in->toDateString();
        }
        if ($deal->expected_stay_length !== null && $deal->expected_stay_period !== null) {
            $bits[] = 'stay '.$deal->expected_stay_length.' '.$deal->expected_stay_period->value;
        }
        if ($deal->desired_size !== null) {
            $bits[] = 'size '.$deal->desired_size.' m²';
        }
        if ($deal->desiredUnitClass !== null) {
            $bits[] = 'class '.$deal->desiredUnitClass->label;
        }

        if ($bits === []) {
            return '';
        }

        return 'Stated needs: '.implode('; ', $bits);
    }

    /**
     * @param  list<array<string, mixed>>  $quotes
     */
    private static function pricesSegment(array $quotes): string
    {
        $unique = self::dedupeQuotes($quotes);
        if ($unique === []) {
            return '';
        }

        $lines = array_map(static fn (array $quote): string => (string) $quote['display'], $unique);

        return "Prices quoted earlier:\n".implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $quotes
     * @return list<array{tool_key: string, unit_class_id: int|null, display: string}>
     */
    private static function dedupeQuotes(array $quotes): array
    {
        $byKey = [];
        foreach ($quotes as $quote) {
            $display = trim((string) ($quote['display'] ?? ''));
            if ($display === '') {
                continue;
            }
            $tool = (string) ($quote['tool_key'] ?? '');
            $class = $quote['unit_class_id'] ?? null;
            $classId = is_numeric($class) ? (int) $class : null;
            $byKey[$tool."\0".($classId === null ? '' : (string) $classId)] = [
                'tool_key' => $tool,
                'unit_class_id' => $classId,
                'display' => $display,
            ];
        }

        return array_values($byKey);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private static function estimatedTokens(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += mb_strlen((string) ($message['content'] ?? ''));
        }

        return intdiv($chars + 3, 4);
    }
}
