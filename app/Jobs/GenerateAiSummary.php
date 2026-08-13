<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\SummaryAgent;
use App\Enums\LogChannel;
use App\Models\AiSummary;
use App\Models\AiUsageEvent;
use App\Models\Employee;
use App\Models\SystemEvent;
use App\Support\Ai\Summaries\SummaryContextResolver;
use App\Support\Ai\Summaries\SummaryPrompt;
use App\Support\Ai\Summaries\SummaryResponseParser;
use App\Support\Ai\SummaryStatus;
use App\Support\RecordsActivity;
use App\Support\RequestId;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class GenerateAiSummary implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public int $timeout;

    public int $uniqueFor;

    public function __construct(public readonly int $aiSummaryId)
    {
        $timeout = (int) config('ai.summaries.timeout', 90);
        $this->timeout = max(30, $timeout);
        $this->uniqueFor = $this->timeout + 60;
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        $row = AiSummary::query()->find($this->aiSummaryId);

        if ($row === null) {
            return 'ai-summary:'.$this->aiSummaryId;
        }

        return $row->summarizable_type.':'.$row->summarizable_id;
    }

    public function handle(SummaryContextResolver $resolver): void
    {
        $summary = AiSummary::query()->find($this->aiSummaryId);
        if ($summary === null) {
            return;
        }

        if ($summary->status !== SummaryStatus::Queued) {
            return;
        }

        $claimed = AiSummary::query()
            ->whereKey($summary->id)
            ->where('status', SummaryStatus::Queued)
            ->update([
                'status' => SummaryStatus::Running->value,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $summary->refresh();
        $subject = $summary->summarizable;
        if ($subject === null) {
            $this->markFailed($summary, 'context_empty');

            return;
        }

        SystemEvent::record('ai.summary.started', $subject, [
            'summary_id' => $summary->id,
        ]);

        $employee = $summary->requestedBy;
        if (! $employee instanceof Employee) {
            $this->markFailed($summary, 'provider_unavailable');

            return;
        }

        Auth::setUser($employee);

        $context = $resolver->resolve($subject, $employee);
        if ($context->isEmpty()) {
            $this->markFailed($summary, 'context_empty');

            return;
        }

        $built = $context->build();
        $digest = $context->digest();
        $counts = $context->counts();
        $entity = $subject->getMorphClass();

        $prompt = SummaryPrompt::assemble($entity, $summary->locale, $built);

        $callId = (string) Str::uuid7();
        Context::add([
            'ai_call_id' => $callId,
            'employee_id' => $employee->id,
            'ai_purpose' => 'summarize',
            'request_id' => RequestId::get() ?? $callId,
        ]);

        try {
            $timeout = (int) config('ai.summaries.timeout', 90);
            $response = (new SummaryAgent($employee))->prompt(
                $prompt,
                timeout: max(30, $timeout),
            );
        } catch (Throwable $e) {
            AiUsageEvent::markFailed($callId);
            $errorCode = $this->mapErrorCode($e);

            $attempts = $this->job !== null ? $this->attempts() : 1;
            if ($attempts < $this->tries && $this->isRetryable($errorCode)) {
                throw $e;
            }

            $this->markFailed($summary, $errorCode, $subject);

            return;
        }

        $parsed = SummaryResponseParser::parse((string) $response->text);
        $usageEvent = AiUsageEvent::query()->where('call_id', $callId)->first();

        DB::transaction(function () use ($summary, $subject, $parsed, $digest, $counts, $response, $usageEvent, $employee): void {
            AiSummary::query()
                ->where('summarizable_type', $summary->summarizable_type)
                ->where('summarizable_id', $summary->summarizable_id)
                ->whereNull('superseded_at')
                ->where('status', SummaryStatus::Succeeded)
                ->whereKeyNot($summary->id)
                ->update(['superseded_at' => now()]);

            $summary->fill([
                'body' => $parsed['body'],
                'highlights' => $parsed['highlights'],
                'provider' => $response->meta->provider ?? null,
                'model' => $response->meta->model ?? null,
                'source_digest' => $digest,
                'source_counts' => $counts,
                'ai_usage_event_id' => $usageEvent?->id,
                'error_code' => null,
                'generated_at' => now(),
                'status' => SummaryStatus::Succeeded,
            ])->save();

            RecordsActivity::log(
                LogChannel::Crm,
                'ai.summary.generated',
                $subject,
                [
                    'summary_id' => $summary->id,
                    'model' => $summary->model,
                    'prompt_version' => $summary->prompt_version,
                    'source_counts' => $counts,
                ],
                $employee,
            );
        });

        SystemEvent::record('ai.summary.committed', $subject, [
            'summary_id' => $summary->id,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        $summary = AiSummary::query()->find($this->aiSummaryId);
        if ($summary === null) {
            return;
        }

        if (in_array($summary->status, [SummaryStatus::Succeeded, SummaryStatus::Failed], true)) {
            return;
        }

        $callId = Context::get('ai_call_id');
        if (is_string($callId) && $callId !== '') {
            AiUsageEvent::markFailed($callId);
        }

        $this->markFailed(
            $summary,
            $e !== null ? $this->mapErrorCode($e) : 'provider_unavailable',
            $summary->summarizable,
        );
    }

    private function markFailed(AiSummary $summary, string $errorCode, mixed $subject = null): void
    {
        $summary->fill([
            'status' => SummaryStatus::Failed,
            'error_code' => $errorCode,
        ])->save();

        $subject ??= $summary->summarizable;
        if ($subject !== null) {
            SystemEvent::record('ai.summary.failed', $subject, [
                'summary_id' => $summary->id,
                'error_code' => $errorCode,
            ]);
        }
    }

    private function mapErrorCode(Throwable $e): string
    {
        if ($e instanceof RateLimitedException) {
            return 'rate_limited';
        }

        if ($e instanceof ProviderOverloadedException) {
            return 'provider_unavailable';
        }

        $message = strtolower($e->getMessage());
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }

        return 'provider_unavailable';
    }

    private function isRetryable(string $errorCode): bool
    {
        return in_array($errorCode, ['timeout', 'rate_limited', 'provider_unavailable'], true);
    }
}
