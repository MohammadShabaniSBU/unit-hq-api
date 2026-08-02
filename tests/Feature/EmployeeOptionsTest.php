<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->getJson('/api/employees/options')->assertUnauthorized();
    }

    public function test_lists_employees_ordered_by_name(): void
    {
        $viewer = Employee::factory()->manager()->create();
        Sanctum::actingAs($viewer);

        $zoe = Employee::factory()->staff()->create(['name' => 'Zoe Staff']);
        $amir = Employee::factory()->manager()->create(['name' => 'Amir Manager']);

        $this->getJson('/api/employees/options')
            ->assertOk()
            ->assertJsonPath('data.0.value', $amir->id)
            ->assertJsonPath('data.0.label', 'Amir Manager')
            ->assertJsonFragment(['value' => $zoe->id, 'label' => 'Zoe Staff'])
            ->assertJsonFragment(['value' => $viewer->id]);
    }
}
