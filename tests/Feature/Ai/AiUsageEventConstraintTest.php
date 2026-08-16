<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use App\Models\AiUsageEvent;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiUsageEventConstraintTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function agent_attributed_row_without_employee_inserts(): void
    {
        $agent = AiAgent::factory()->create();

        $event = AiUsageEvent::query()->create([
            'call_id' => (string) Str::uuid7(),
            'employee_id' => null,
            'ai_agent_id' => $agent->id,
            'purpose' => 'copilot',
            'status' => AiUsageEvent::STATUS_STARTED,
            'started_at' => now(),
        ]);

        $this->assertTrue($event->exists);
        $this->assertNull($event->employee_id);
        $this->assertSame($agent->id, $event->ai_agent_id);
    }

    #[Test]
    public function row_with_neither_employee_nor_agent_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        AiUsageEvent::query()->create([
            'call_id' => (string) Str::uuid7(),
            'employee_id' => null,
            'ai_agent_id' => null,
            'purpose' => 'copilot',
            'status' => AiUsageEvent::STATUS_STARTED,
            'started_at' => now(),
        ]);
    }

    #[Test]
    public function employee_attributed_row_without_agent_still_inserts(): void
    {
        $employee = Employee::factory()->create();

        $event = AiUsageEvent::query()->create([
            'call_id' => (string) Str::uuid7(),
            'employee_id' => $employee->id,
            'ai_agent_id' => null,
            'purpose' => 'copilot',
            'status' => AiUsageEvent::STATUS_STARTED,
            'started_at' => now(),
        ]);

        $this->assertTrue($event->exists);
        $this->assertSame($employee->id, $event->employee_id);
        $this->assertNull($event->ai_agent_id);
    }
}
