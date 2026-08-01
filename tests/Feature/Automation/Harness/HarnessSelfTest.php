<?php

declare(strict_types=1);

namespace Tests\Feature\Automation\Harness;

use App\Enums\AutomationRunStatus;
use App\Models\Contact;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\AutomationHarness;
use Tests\TestCase;

class HarnessSelfTest extends TestCase
{
    use RefreshDatabase;

    public function test_prints_step_table_on_failure(): void
    {
        Carbon::setTestNow('2026-08-01 10:00:00');
        Employee::factory()->create();

        $contact = Contact::factory()->create([
            'first_name' => 'Self',
            'last_name' => 'Test',
            'email' => 'self-'.uniqid().'@example.com',
        ]);

        $harness = AutomationHarness::load('linear_three_actions')
            ->trigger('object_created', $contact);

        try {
            $harness->assertRunStatus(AutomationRunStatus::Failed);
            $this->fail('Expected assertion failure');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('node_key', $message);
            $this->assertStringContainsString('node_type', $message);
            $this->assertStringContainsString('status', $message);
            $this->assertStringContainsString('trigger', $message);
        } finally {
            Carbon::setTestNow();
        }
    }
}
