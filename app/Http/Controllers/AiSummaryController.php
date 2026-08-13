<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AiSummaryResource;
use App\Jobs\GenerateAiSummary;
use App\Models\AiSummary;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Employee;
use App\Support\Ai\Summaries\SummaryContext;
use App\Support\Ai\Summaries\SummaryContextResolver;
use App\Support\Ai\SummaryStatus;
use App\Support\Auth\Permission;
use App\Support\Auth\SubjectSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AiSummaryController extends Controller
{
    public function show(Request $request, SummaryContextResolver $resolver): JsonResponse
    {
        $subject = $this->resolveSubject($request);
        Gate::authorize(Permission::AiSummaryView->value, $subject);
        $this->assertContactListVisible($request, $subject);

        /** @var Employee $employee */
        $employee = $request->user();
        $context = $resolver->resolve($subject, $employee);

        $current = AiSummary::query()
            ->where('summarizable_type', $subject->getMorphClass())
            ->where('summarizable_id', $subject->getKey())
            ->current()
            ->first();

        $inFlight = AiSummary::query()
            ->where('summarizable_type', $subject->getMorphClass())
            ->where('summarizable_id', $subject->getKey())
            ->inFlight()
            ->latest('id')
            ->first();

        $lastFailed = AiSummary::query()
            ->where('summarizable_type', $subject->getMorphClass())
            ->where('summarizable_id', $subject->getKey())
            ->where('status', SummaryStatus::Failed)
            ->latest('id')
            ->first();

        // Ignore a failed row that is older than the current summary.
        if (
            $lastFailed !== null
            && $current !== null
            && $current->generated_at !== null
            && $lastFailed->created_at !== null
            && $lastFailed->created_at->lte($current->generated_at)
        ) {
            $lastFailed = null;
        }

        $isStale = false;
        if ($current !== null && is_string($current->source_digest) && $current->source_digest !== '') {
            $isStale = $current->source_digest !== $context->digest();
        }

        return $this->success([
            'current' => $current !== null ? AiSummaryResource::make($current) : null,
            'in_flight' => $inFlight !== null ? AiSummaryResource::make($inFlight) : null,
            'last_failed' => $lastFailed !== null ? AiSummaryResource::make($lastFailed) : null,
            'is_stale' => $isStale,
            'can_generate' => $this->canGenerate($employee, $subject, $context, $current, $inFlight),
        ], 'AI summary retrieved successfully.');
    }

    public function store(Request $request, SummaryContextResolver $resolver): JsonResponse
    {
        $subject = $this->resolveSubject($request);
        Gate::authorize(Permission::AiSummaryGenerate->value, $subject);
        $this->assertContactListVisible($request, $subject);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['en', 'es', 'fr'])],
        ]);

        $locale = $validated['locale'] ?? app()->getLocale();
        if (! in_array($locale, ['en', 'es', 'fr'], true)) {
            $locale = 'en';
        }

        $inFlight = AiSummary::query()
            ->where('summarizable_type', $subject->getMorphClass())
            ->where('summarizable_id', $subject->getKey())
            ->inFlight()
            ->exists();

        if ($inFlight) {
            return $this->error('errors.ai_summary.in_flight', [], 409);
        }

        $current = AiSummary::query()
            ->where('summarizable_type', $subject->getMorphClass())
            ->where('summarizable_id', $subject->getKey())
            ->current()
            ->first();

        $minSeconds = (int) config('ai.summaries.min_regenerate_seconds', 30);
        if (
            $current !== null
            && $current->generated_at !== null
            && $current->generated_at->gt(now()->subSeconds($minSeconds))
        ) {
            return $this->error('errors.ai_summary.too_soon', [], 429);
        }

        $context = $resolver->resolve($subject, $employee);
        if ($context->isEmpty()) {
            return $this->error('errors.ai_summary.context_empty', [], 422);
        }

        try {
            $summary = AiSummary::query()->create([
                'summarizable_type' => $subject->getMorphClass(),
                'summarizable_id' => $subject->getKey(),
                'status' => SummaryStatus::Queued,
                'locale' => $locale,
                'prompt_version' => (string) config('ai.summaries.prompt_version', 'v1'),
                'requested_by_employee_id' => $employee->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->error('errors.ai_summary.in_flight', [], 409);
        }

        GenerateAiSummary::dispatch($summary->id);

        return $this->accepted(
            AiSummaryResource::make($summary),
            'AI summary generation queued.'
        );
    }

    public function history(Request $request): JsonResponse
    {
        $subject = $this->resolveSubject($request);
        Gate::authorize(Permission::AiSummaryView->value, $subject);
        $this->assertContactListVisible($request, $subject);

        $rows = AiSummary::query()
            ->where('summarizable_type', $subject->getMorphClass())
            ->where('summarizable_id', $subject->getKey())
            ->whereNotNull('superseded_at')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->through(fn (AiSummary $row) => AiSummaryResource::make($row));

        return $this->paginated($rows, 'AI summary history retrieved successfully.');
    }

    private function resolveSubject(Request $request): Model
    {
        $contact = $request->route('contact');
        if ($contact instanceof Contact) {
            return $contact;
        }
        // Contact is not in VisibleRouteBindings; without a Contact type-hint on
        // the action, {contact} stays a raw id string.
        if ($contact !== null && $contact !== '') {
            return Contact::query()->findOrFail($contact);
        }

        $deal = $request->route('deal');
        if ($deal instanceof Deal) {
            return $deal;
        }
        if ($deal !== null && $deal !== '') {
            return Deal::query()->findOrFail($deal);
        }

        abort(404);
    }

    /**
     * Contact is company-level in SubjectSite; list visibility still applies
     * (D-RBAC-1). Outside the employee's list grants → 404.
     */
    private function assertContactListVisible(Request $request, Model $subject): void
    {
        if (! $subject instanceof Contact) {
            return;
        }

        /** @var Employee $employee */
        $employee = $request->user();

        $visible = Contact::query()
            ->visibleTo($employee, Permission::ContactView)
            ->whereKey($subject->id)
            ->exists();

        if (! $visible) {
            abort(404);
        }
    }

    private function canGenerate(
        Employee $employee,
        Model $subject,
        SummaryContext $context,
        ?AiSummary $current,
        ?AiSummary $inFlight,
    ): bool {
        if (! $employee->allowsPermission(Permission::AiSummaryGenerate, SubjectSite::for($subject))) {
            return false;
        }

        if ($inFlight !== null) {
            return false;
        }

        if ($context->isEmpty()) {
            return false;
        }

        $minSeconds = (int) config('ai.summaries.min_regenerate_seconds', 30);
        if (
            $current !== null
            && $current->generated_at !== null
            && $current->generated_at->gt(now()->subSeconds($minSeconds))
        ) {
            return false;
        }

        return true;
    }
}
