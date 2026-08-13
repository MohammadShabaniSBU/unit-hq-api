<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiSummary;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Ai\SummaryStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiSummarySchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function partial_unique_rejects_second_current_succeeded_summary(): void
    {
        $contact = Contact::factory()->create();
        $employee = Employee::factory()->create();

        AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $employee->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $employee->id,
            'body' => 'Second current summary must fail.',
        ]);
    }

    #[Test]
    public function partial_unique_rejects_second_in_flight_summary(): void
    {
        $contact = Contact::factory()->create();
        $employee = Employee::factory()->create();

        AiSummary::factory()->forContact($contact)->create([
            'status' => SummaryStatus::Queued,
            'requested_by_employee_id' => $employee->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        AiSummary::factory()->running()->forContact($contact)->create([
            'requested_by_employee_id' => $employee->id,
        ]);
    }

    #[Test]
    public function succeeded_current_and_superseded_can_coexist(): void
    {
        $contact = Contact::factory()->create();
        $employee = Employee::factory()->create();

        AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $employee->id,
            'superseded_at' => now()->subMinute(),
        ]);

        $current = AiSummary::factory()->succeeded()->forContact($contact)->create([
            'requested_by_employee_id' => $employee->id,
            'body' => 'Current summary.',
        ]);

        $this->assertTrue($current->exists);
        $this->assertSame(1, $contact->aiSummaries()->current()->count());
    }
}
