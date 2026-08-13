<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiSummary;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Employee;
use App\Support\Ai\SummaryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiSummaryRedactionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function contacts_redact_clears_summary_content_on_contact_and_deals(): void
    {
        $employee = Employee::factory()->manager()->create();
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create(['contact_id' => $contact->id]);

        $current = AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $employee->id,
            'body' => 'Current contact summary with PII.',
            'highlights' => [['key' => 'stage', 'label_key' => null, 'value' => 'Lead']],
            'source_counts' => ['notes' => 1],
        ]);

        $superseded = AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $employee->id,
            'body' => 'Old contact summary.',
            'superseded_at' => now()->subDay(),
            'highlights' => [['key' => 'balance', 'label_key' => null, 'value' => '10 EUR']],
            'source_counts' => ['notes' => 2],
        ]);

        $dealSummary = AiSummary::factory()->succeeded()->create([
            'summarizable_type' => $deal->getMorphClass(),
            'summarizable_id' => $deal->id,
            'requested_by_employee_id' => $employee->id,
            'body' => 'Deal summary body.',
            'error_code' => null,
            'highlights' => [['key' => 'forecast', 'label_key' => null, 'value' => 'Soon']],
            'source_counts' => ['offers' => 1],
        ]);

        Artisan::call('contacts:redact', ['contact' => $contact->id]);

        foreach ([$current, $superseded, $dealSummary] as $row) {
            $row->refresh();
            $this->assertNull($row->body);
            $this->assertNull($row->highlights);
            $this->assertNull($row->source_counts);
            $this->assertNull($row->error_code);
            $this->assertSame(SummaryStatus::Succeeded, $row->status);
        }
    }
}
