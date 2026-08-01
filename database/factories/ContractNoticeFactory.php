<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContractNoticeType;
use App\Models\Contract;
use App\Models\ContractNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractNotice>
 */
class ContractNoticeFactory extends Factory
{
    protected $model = ContractNotice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'notice_type' => ContractNoticeType::Overdue,
            'effective_date' => null,
            'required_by' => null,
            'sent_at' => null,
            'sent_channel' => null,
            'sent_to' => null,
            'document_ref' => null,
            'short_notice_reason' => null,
            'contract_item_id' => null,
            'created_by' => null,
        ];
    }

    public function rateChange(): static
    {
        return $this->state(fn () => [
            'notice_type' => ContractNoticeType::RateChange,
            'effective_date' => now()->addDays(30)->toDateString(),
        ]);
    }
}
