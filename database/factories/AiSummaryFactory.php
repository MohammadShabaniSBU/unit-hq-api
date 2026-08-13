<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiSummary;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Ai\SummaryStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiSummary>
 */
class AiSummaryFactory extends Factory
{
    protected $model = AiSummary::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'summarizable_type' => 'contact',
            'summarizable_id' => Contact::factory(),
            'status' => SummaryStatus::Queued,
            'body' => null,
            'highlights' => null,
            'locale' => 'en',
            'provider' => null,
            'model' => null,
            'prompt_version' => 'v1',
            'source_digest' => null,
            'source_counts' => null,
            'ai_usage_event_id' => null,
            'error_code' => null,
            'requested_by_employee_id' => Employee::factory(),
            'generated_at' => null,
            'superseded_at' => null,
        ];
    }

    public function succeeded(?string $body = 'A short summary of the contact.'): static
    {
        return $this->state(fn (): array => [
            'status' => SummaryStatus::Succeeded,
            'body' => $body,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'source_digest' => hash('sha256', 'fixture'),
            'source_counts' => ['interactions' => 1, 'notes' => 0],
            'generated_at' => now(),
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => SummaryStatus::Running,
        ]);
    }

    public function failed(string $errorCode = 'provider_unavailable'): static
    {
        return $this->state(fn (): array => [
            'status' => SummaryStatus::Failed,
            'error_code' => $errorCode,
        ]);
    }

    public function forContact(Contact $contact): static
    {
        return $this->state(fn (): array => [
            'summarizable_type' => $contact->getMorphClass(),
            'summarizable_id' => $contact->id,
        ]);
    }
}
