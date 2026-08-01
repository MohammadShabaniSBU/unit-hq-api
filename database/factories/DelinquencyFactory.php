<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DelinquencyCureTrigger;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delinquency>
 */
class DelinquencyFactory extends Factory
{
    protected $model = Delinquency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $opened = fake()->dateTimeBetween('-30 days', '-1 day')->format('Y-m-d');

        return [
            'contract_id' => Contract::factory(),
            'delinquency_policy_id' => DelinquencyPolicy::factory(),
            'anchor_due_date' => $opened,
            'opened_on' => $opened,
            'cured_on' => null,
            'cure_trigger' => null,
            'paused_at' => null,
            'paused_reason' => null,
            'paused_by' => null,
        ];
    }

    public function cured(?DelinquencyCureTrigger $trigger = null): static
    {
        return $this->state(fn () => [
            'cured_on' => now()->toDateString(),
            'cure_trigger' => $trigger ?? DelinquencyCureTrigger::Payment,
        ]);
    }

    public function paused(?string $reason = 'dispute'): static
    {
        return $this->state(fn () => [
            'paused_at' => now(),
            'paused_reason' => $reason,
        ]);
    }
}
