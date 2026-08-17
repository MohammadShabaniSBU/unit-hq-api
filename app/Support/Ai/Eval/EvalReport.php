<?php

declare(strict_types=1);

namespace App\Support\Ai\Eval;

final class EvalReport
{
    /** @var list<EvalCaseResult> */
    public array $cases = [];

    public function add(EvalCaseResult $case): void
    {
        $this->cases[] = $case;
    }

    public function passed(): bool
    {
        foreach ($this->cases as $case) {
            if (! $case->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $byAgent = [];
        foreach ($this->cases as $case) {
            $byAgent[$case->agent] ??= ['passed' => 0, 'total' => 0, 'failed' => []];
            $byAgent[$case->agent]['total']++;
            if ($case->passed) {
                $byAgent[$case->agent]['passed']++;
            } else {
                $byAgent[$case->agent]['failed'][] = $case->id;
            }
        }

        return [
            'passed' => $this->passed(),
            'summary' => $byAgent,
            'results' => array_map(fn (EvalCaseResult $case): array => [
                'id' => $case->id,
                'agent' => $case->agent,
                'passed' => $case->passed,
                'failures' => $case->failures,
                'live_only' => $case->liveOnly,
                'sms_segments' => $case->smsSegments,
            ], $this->cases),
        ];
    }

    public function toHuman(): string
    {
        $lines = [];
        $byAgent = [];
        foreach ($this->cases as $case) {
            $byAgent[$case->agent][] = $case;
        }

        foreach ($byAgent as $agent => $cases) {
            $total = count($cases);
            $pass = count(array_filter($cases, fn (EvalCaseResult $c): bool => $c->passed));
            $blocked = count(array_filter($cases, fn (EvalCaseResult $c): bool => $c->blockedUnexpectedly));
            $tools = 0;
            $tokens = 0;
            $turns = max(1, $total);
            foreach ($cases as $case) {
                $tools += $case->toolCalls;
                $tokens += $case->tokens;
            }
            $avgTools = round($tools / $turns, 1);
            $avgTok = (int) round($tokens / $turns);
            $failN = $total - $pass;
            $failBit = $failN === 0 ? '0 FAIL' : $failN.' FAIL';
            $smsBits = [];
            foreach ($cases as $case) {
                if ($case->smsSegments !== null) {
                    $smsBits[] = "{$case->id} {$case->smsSegments} sms segs";
                }
            }
            $smsBit = $smsBits === [] ? '' : '   '.implode(', ', $smsBits);
            $lines[] = sprintf(
                '%-8s %d/%d pass   %d blocked-unexpectedly   avg %s tools/turn   %s tok/turn   %s%s',
                $agent,
                $pass,
                $total,
                $blocked,
                $avgTools,
                number_format($avgTok),
                $failBit,
                $smsBit,
            );
        }

        $lines[] = '';
        foreach ($this->cases as $case) {
            if ($case->passed) {
                continue;
            }
            $lines[] = "FAIL {$case->id}";
            foreach ($case->failures as $failure) {
                $lines[] = '  '.$failure;
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines))."\n";
    }
}
