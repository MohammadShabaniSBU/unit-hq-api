<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\SummaryAgent;
use App\Enums\LogChannel;
use App\Jobs\GenerateAiSummary;
use App\Models\Activity;
use App\Models\AiSummary;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Interaction;
use App\Models\SystemEvent;
use App\Support\Ai\Summaries\SummaryContextResolver;
use App\Support\Ai\SummaryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Exceptions\RateLimitedException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateAiSummaryJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function success_supersedes_previous_and_never_logs_body(): void
    {
        $employee = Employee::factory()->manager()->create();
        $contact = Contact::factory()->create();
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'occurred_at' => now(),
            'content' => 'Interested in a unit.',
        ]);

        $previous = AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $employee->id,
            'body' => 'Old summary body that must not leak.',
        ]);

        $queued = AiSummary::factory()->forContact($contact)->create([
            'status' => SummaryStatus::Queued,
            'requested_by_employee_id' => $employee->id,
            'locale' => 'en',
        ]);

        SummaryAgent::fake([
            json_encode([
                'body' => 'Ada is exploring storage options.',
                'highlights' => [
                    ['key' => 'stage', 'value' => 'Qualified'],
                    ['key' => 'unknown_key', 'value' => 'drop me'],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        (new GenerateAiSummary($queued->id))->handle(app(SummaryContextResolver::class));

        $queued->refresh();
        $previous->refresh();

        $this->assertSame(SummaryStatus::Succeeded, $queued->status);
        $this->assertSame('Ada is exploring storage options.', $queued->body);
        $this->assertNotNull($queued->generated_at);
        $this->assertNotNull($previous->superseded_at);
        $this->assertSame(
            [['key' => 'stage', 'label_key' => null, 'value' => 'Qualified']],
            $queued->highlights
        );

        $activity = Activity::query()
            ->where('description', 'ai.summary.generated')
            ->where('log_name', LogChannel::Crm->value)
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $props = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('body', $props);
        $this->assertSame($queued->id, $props['summary_id'] ?? null);

        $events = SystemEvent::query()
            ->whereIn('event', ['ai.summary.started', 'ai.summary.committed'])
            ->get();
        $this->assertTrue($events->contains(fn (SystemEvent $e) => $e->event === 'ai.summary.started'));
        $this->assertTrue($events->contains(fn (SystemEvent $e) => $e->event === 'ai.summary.committed'));
        foreach ($events as $event) {
            $this->assertArrayNotHasKey('body', $event->payload ?? []);
        }
    }

    #[Test]
    public function provider_failure_leaves_previous_current_untouched(): void
    {
        $employee = Employee::factory()->manager()->create();
        $contact = Contact::factory()->create();
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'occurred_at' => now(),
            'content' => 'Hello',
        ]);

        $previous = AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $employee->id,
        ]);

        $queued = AiSummary::factory()->forContact($contact)->create([
            'status' => SummaryStatus::Queued,
            'requested_by_employee_id' => $employee->id,
        ]);

        SummaryAgent::fake(fn () => throw RateLimitedException::forProvider('anthropic', 429));

        $job = new GenerateAiSummary($queued->id);
        // Sync dispatch: attempts()===1 and tries===1 → mark failed, do not rethrow.
        $job->tries = 1;
        $job->handle(app(SummaryContextResolver::class));

        $queued->refresh();
        $previous->refresh();

        $this->assertSame(SummaryStatus::Failed, $queued->status);
        $this->assertSame('rate_limited', $queued->error_code);
        $this->assertNull($previous->superseded_at);
        $this->assertSame(SummaryStatus::Succeeded, $previous->status);
    }

    #[Test]
    public function concurrent_claim_race_second_worker_no_ops(): void
    {
        $employee = Employee::factory()->manager()->create();
        $contact = Contact::factory()->create();
        Interaction::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'occurred_at' => now(),
            'content' => 'Hello',
        ]);

        $queued = AiSummary::factory()->forContact($contact)->create([
            'status' => SummaryStatus::Running,
            'requested_by_employee_id' => $employee->id,
        ]);

        SummaryAgent::fake(['should not be called'])->preventStrayPrompts();

        (new GenerateAiSummary($queued->id))->handle(app(SummaryContextResolver::class));

        SummaryAgent::assertNeverPrompted();
        $this->assertSame(SummaryStatus::Running, $queued->fresh()->status);
    }

    #[Test]
    public function empty_context_fails_without_provider_call(): void
    {
        $employee = Employee::factory()->manager()->create();
        $contact = Contact::factory()->create();

        $queued = AiSummary::factory()->forContact($contact)->create([
            'status' => SummaryStatus::Queued,
            'requested_by_employee_id' => $employee->id,
        ]);

        SummaryAgent::fake(['should not be called'])->preventStrayPrompts();

        (new GenerateAiSummary($queued->id))->handle(app(SummaryContextResolver::class));

        SummaryAgent::assertNeverPrompted();
        $this->assertSame(SummaryStatus::Failed, $queued->fresh()->status);
        $this->assertSame('context_empty', $queued->fresh()->error_code);
    }
}
