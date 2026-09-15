<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Ai\Agents\CrmCopilotAgent;
use App\Ai\Tools\ResolveCalendar;
use App\Models\Site;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class CrmCopilotAgentInstructionsTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function instructions_mention_resolve_calendar_and_list_active_sites(): void
    {
        $madrid = Site::factory()->create(['name' => 'Centro Madrid']);
        $barcelona = Site::factory()->create(['name' => 'Barcelona']);
        Site::factory()->create([
            'name' => 'Archived Depot',
            'archived_at' => now(),
        ]);

        $employee = $this->employeeWithPermission(Permission::ContactView);
        $instructions = (string) (new CrmCopilotAgent($employee))->instructions();

        $this->assertStringContainsString('ResolveCalendar', $instructions);
        $this->assertStringContainsString("- {$madrid->name} (id: {$madrid->id})", $instructions);
        $this->assertStringContainsString("- {$barcelona->name} (id: {$barcelona->id})", $instructions);
        $this->assertStringNotContainsString('Archived Depot', $instructions);
    }

    #[Test]
    public function site_directory_respects_employee_site_scope(): void
    {
        $granted = Site::factory()->create(['name' => 'Granted Site']);
        $other = Site::factory()->create(['name' => 'Other Site']);

        $employee = $this->employeeWithSiteScopedPermission(Permission::ContactView, $granted);
        $instructions = (string) (new CrmCopilotAgent($employee))->instructions();

        $this->assertStringContainsString("- {$granted->name} (id: {$granted->id})", $instructions);
        $this->assertStringNotContainsString($other->name, $instructions);
    }

    #[Test]
    public function empty_directory_when_no_sites_are_visible(): void
    {
        Site::factory()->create(['name' => 'Hidden Site']);

        $employee = $this->employeeWithoutPermissions();
        $instructions = (string) (new CrmCopilotAgent($employee))->instructions();

        $this->assertStringContainsString('No active sites are visible to this operator.', $instructions);
        $this->assertStringNotContainsString('Hidden Site', $instructions);
    }

    #[Test]
    public function tools_include_resolve_calendar(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactView);
        $tools = iterator_to_array((new CrmCopilotAgent($employee))->tools(), false);

        $this->assertTrue(
            collect($tools)->contains(fn (mixed $tool): bool => $tool instanceof ResolveCalendar),
        );
    }
}
