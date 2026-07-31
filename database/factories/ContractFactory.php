<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\ContractStatus;
use App\Enums\MoveOutSettlement;
use App\Enums\ProrationMethod;
use App\Enums\TransferBilling;
use App\Models\Contact;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-6 months', '-1 month');

        return [
            'contact_id'             => Contact::factory(),
            'start_date'             => $start->format('Y-m-d'),
            'end_date'               => null,
            'billing_interval'       => BillingInterval::Month,
            'billing_interval_count' => 1,
            'billing_anchor_model'   => BillingAnchorModel::Anniversary,
            'billing_anchor_date'    => $start->format('Y-m-d'),
            'billed_through'         => $start->format('Y-m-d'),
            'proration_method'       => ProrationMethod::Daily,
            'move_in_date'           => $start->format('Y-m-d'),
            'deposit_amount'         => '0.00',
            'currency'               => 'EUR',
            'status'                 => ContractStatus::Active,
            'notice_period_days'     => 14,
            'move_out_settlement'    => MoveOutSettlement::None,
            'transfer_billing'       => TransferBilling::ProrateImmediately,
            'signed_at'              => $start,
        ];
    }
}
