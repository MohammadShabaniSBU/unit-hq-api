<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\DelinquencyPolicyAction;
use App\Models\DelinquencyPolicy;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class PolicyTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();

        Employee::factory()->manager()->create();
    }

    public function test_param_shapes_per_action(): void
    {
        $cases = [
            // valid — assess_late_fee percent
            [
                'valid' => true,
                'steps' => [[
                    'offset_days' => 5,
                    'action' => DelinquencyPolicyAction::AssessLateFee->value,
                    'params' => ['type' => 'percent', 'percent' => '10.00', 'cap_per_case' => '50.00'],
                ]],
            ],
            // valid — assess_late_fee flat
            [
                'valid' => true,
                'steps' => [[
                    'offset_days' => 5,
                    'action' => DelinquencyPolicyAction::AssessLateFee->value,
                    'params' => ['type' => 'flat', 'amount' => '10.00'],
                ]],
            ],
            // valid — place_overlock empty params
            [
                'valid' => true,
                'steps' => [[
                    'offset_days' => 12,
                    'action' => DelinquencyPolicyAction::PlaceOverlock->value,
                    'params' => [],
                ]],
            ],
            // valid — record_notice
            [
                'valid' => true,
                'steps' => [[
                    'offset_days' => 8,
                    'action' => DelinquencyPolicyAction::RecordNotice->value,
                    'params' => ['notice_type' => 'overdue'],
                ]],
            ],
            // valid — create_task
            [
                'valid' => true,
                'steps' => [[
                    'offset_days' => 20,
                    'action' => DelinquencyPolicyAction::CreateTask->value,
                    'params' => ['title_key' => 'delinquency.task.final_demand', 'urgent' => true],
                ]],
            ],
            // invalid — percent missing percent
            [
                'valid' => false,
                'error' => 'steps.0.params.percent',
                'steps' => [[
                    'offset_days' => 5,
                    'action' => DelinquencyPolicyAction::AssessLateFee->value,
                    'params' => ['type' => 'percent'],
                ]],
            ],
            // invalid — flat missing amount
            [
                'valid' => false,
                'error' => 'steps.0.params.amount',
                'steps' => [[
                    'offset_days' => 5,
                    'action' => DelinquencyPolicyAction::AssessLateFee->value,
                    'params' => ['type' => 'flat'],
                ]],
            ],
            // invalid — unknown param key
            [
                'valid' => false,
                'error' => 'steps.0.params',
                'steps' => [[
                    'offset_days' => 12,
                    'action' => DelinquencyPolicyAction::PlaceOverlock->value,
                    'params' => ['extra' => true],
                ]],
            ],
            // invalid — bad notice_type
            [
                'valid' => false,
                'error' => 'steps.0.params.notice_type',
                'steps' => [[
                    'offset_days' => 8,
                    'action' => DelinquencyPolicyAction::RecordNotice->value,
                    'params' => ['notice_type' => 'yelling'],
                ]],
            ],
            // invalid — create_task missing urgent
            [
                'valid' => false,
                'error' => 'steps.0.params.urgent',
                'steps' => [[
                    'offset_days' => 20,
                    'action' => DelinquencyPolicyAction::CreateTask->value,
                    'params' => ['title_key' => 'delinquency.task.final_demand'],
                ]],
            ],
            // invalid — negative offset
            [
                'valid' => false,
                'error' => 'steps.0.offset_days',
                'steps' => [[
                    'offset_days' => -1,
                    'action' => DelinquencyPolicyAction::PlaceOverlock->value,
                    'params' => [],
                ]],
            ],
        ];

        foreach ($cases as $i => $case) {
            $response = $this->postJson('/api/delinquency-policies', [
                'name' => 'Shape case '.$i,
                'steps' => $case['steps'],
            ]);

            if ($case['valid']) {
                $response->assertCreated();
            } else {
                $response->assertStatus(422)->assertJsonValidationErrors([$case['error']]);
            }
        }
    }

    public function test_revoke_access_accepted(): void
    {
        $response = $this->postJson('/api/delinquency-policies', [
            'name' => 'Access revoke ladder',
            'steps' => [[
                'offset_days' => 30,
                'action' => DelinquencyPolicyAction::RevokeAccess->value,
                'params' => [],
            ]],
        ]);

        $response->assertCreated();
        $this->assertSame(
            DelinquencyPolicyAction::RevokeAccess->value,
            $response->json('data.steps.0.action'),
        );
        $this->assertSame([], $response->json('data.steps.0.params'));
        $this->assertTrue($response->json('data.auto_restore_access'));
    }

    public function test_offset_action_uniqueness(): void
    {
        $this->postJson('/api/delinquency-policies', [
            'name' => 'Same day different actions',
            'steps' => [
                [
                    'offset_days' => 20,
                    'action' => DelinquencyPolicyAction::RecordNotice->value,
                    'params' => ['notice_type' => 'final_demand'],
                    'sort' => 0,
                ],
                [
                    'offset_days' => 20,
                    'action' => DelinquencyPolicyAction::CreateTask->value,
                    'params' => ['title_key' => 'delinquency.task.final_demand', 'urgent' => true],
                    'sort' => 1,
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/delinquency-policies', [
            'name' => 'Same day same action',
            'steps' => [
                [
                    'offset_days' => 8,
                    'action' => DelinquencyPolicyAction::RecordNotice->value,
                    'params' => ['notice_type' => 'overdue'],
                    'sort' => 0,
                ],
                [
                    'offset_days' => 8,
                    'action' => DelinquencyPolicyAction::RecordNotice->value,
                    'params' => ['notice_type' => 'payment_reminder'],
                    'sort' => 1,
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['steps.1.action']);
    }

    public function test_archive_guards(): void
    {
        $unused = DelinquencyPolicy::query()->create([
            'name' => 'Unused',
            'auto_release_overlock' => true,
        ]);
        $unused->steps()->create([
            'offset_days' => 5,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 0,
        ]);

        $this->postJson("/api/delinquency-policies/{$unused->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', fn ($v) => $v !== null);

        $inUse = DelinquencyPolicy::query()->create([
            'name' => 'In use',
            'auto_release_overlock' => true,
        ]);
        $inUse->steps()->create([
            'offset_days' => 5,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 0,
        ]);

        Site::factory()->create(['delinquency_policy_id' => $inUse->id]);

        $this->postJson("/api/delinquency-policies/{$inUse->id}/archive")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delinquency_policy']);
    }

    public function test_site_assignment_and_options(): void
    {
        $policy = DelinquencyPolicy::query()->create([
            'name' => 'Assignable',
            'auto_release_overlock' => true,
        ]);
        $policy->steps()->create([
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 0,
        ]);

        $site = Site::factory()->create(['delinquency_policy_id' => null]);

        $this->patchJson("/api/sites/{$site->id}", [
            'delinquency_policy_id' => $policy->id,
        ])->assertOk()->assertJsonPath('data.delinquency_policy_id', $policy->id);

        $this->patchJson("/api/sites/{$site->id}", [
            'delinquency_policy_id' => null,
        ])->assertOk()->assertJsonPath('data.delinquency_policy_id', null);

        $this->getJson('/api/delinquency-policies/options')
            ->assertOk()
            ->assertJsonFragment(['value' => $policy->id, 'label' => 'Assignable']);

        $index = $this->getJson('/api/delinquency-policies')->assertOk();
        $this->assertSame('0.00', $index->json('data.fiscal.late_fee_tax'));
        $this->assertFalse($index->json('data.fiscal.invoice_late_fees'));
    }
}
