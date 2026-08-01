<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Models\Delinquency;
use App\Models\DelinquencyStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DelinquencyStep>
 */
class DelinquencyStepFactory extends Factory
{
    protected $model = DelinquencyStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delinquency_id' => Delinquency::factory(),
            'policy_step_id' => null,
            'action' => DelinquencyStepAction::RecordNotice,
            'executed_on' => now()->toDateString(),
            'trigger' => DelinquencyStepTrigger::Manual,
            'charge_id' => null,
            'unit_hold_id' => null,
            'contract_notice_id' => null,
            'task_id' => null,
            'detail' => null,
            'created_by' => null,
        ];
    }
}
