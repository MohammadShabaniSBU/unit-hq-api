<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ChargeType;
use App\Enums\ContractEndedReason;
use App\Enums\ContractItemChangeReason;
use App\Enums\ContractStatus;
use App\Enums\DepositPayoutStatus;
use App\Enums\DepositSettlementOutcome;
use App\Enums\HoldType;
use App\Enums\MoveOutSettlement;
use App\Enums\ReservationStatus;
use App\Enums\TransferPricingMode;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractTransfer;
use App\Models\DepositSettlement;
use App\Models\DepositSettlementLine;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use App\Support\Billing\ContractBilling;
use App\Support\Billing\CurrencyGuard;
use App\Support\Occupancy\HoldGuard;
use App\Support\Occupancy\OccupancyGuard;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Assigns occupancy and hold facts for every seeded unit state.
 * Draws without replacement from per-site pools; seeds through guards.
 */
class OccupancySeeder extends Seeder
{
    use WithoutModelEvents;

    /** Approximate share of units per site (remaining → available). */
    private const SHARE_OCCUPIED = 0.55;

    private const SHARE_RESERVED = 0.06;

    private const SHARE_MAINTENANCE = 0.03;

    private const SHARE_DAMAGED = 0.02;

    private const SHARE_STAFF_USE = 0.01;

    private const SHARE_OTHER = 0.01;

    private const SHARE_OVERLOCK_OF_OCCUPIED = 0.02;

    /** @var array<string, string> */
    private array $edgeCases = [];

    /** @var array<string, int> */
    private array $stateCounts = [
        'occupied' => 0,
        'available' => 0,
        'reserved' => 0,
        'maintenance' => 0,
        'damaged' => 0,
        'staff_use' => 0,
        'other' => 0,
        'overlock' => 0,
    ];

    public function run(): void
    {
        $manager = Employee::query()->where('role', 'manager')->firstOrFail();
        $contacts = Contact::query()->get();
        $billing = Setting::billing();
        $sites = Site::query()->with('country')->orderBy('id')->get();

        /** @var Collection<int, Collection<int, Unit>> $pools */
        $pools = Unit::query()
            ->with('site')
            ->get()
            ->groupBy('site_id')
            ->map(fn (Collection $units) => $units->values()->shuffle());

        foreach ($sites as $site) {
            $pool = $pools->get($site->id, collect())->values();
            if ($pool->isEmpty()) {
                continue;
            }

            $this->seedEdgeCasesForSite($site, $pool, $contacts, $billing, $manager);
            $this->seedDistributionForSite($site, $pool, $contacts, $billing, $manager);
        }

        $this->seedSupersededItemVersion($manager);
        $this->printSummary($sites);
    }

    /**
     * At least one contract carries a superseded item version so History UIs have content.
     */
    private function seedSupersededItemVersion(Employee $manager): void
    {
        $item = ContractItem::query()
            ->where('item_type', 'unit')
            ->whereNull('effective_to')
            ->whereNull('change_reason')
            ->with(['price', 'contract'])
            ->first();

        if ($item === null || $item->contract === null || $item->price === null) {
            return;
        }

        $changeOn = CarbonImmutable::parse($item->effective_from)->addMonths(2);
        $newAmount = BillingMath::round2(bcmul((string) $item->price->amount, '1.10', 4));

        $contractPrice = Price::query()->create([
            'priceable_type' => $item->price->priceable_type,
            'priceable_id'   => $item->price->priceable_id,
            'scope'          => Price::SCOPE_CONTRACT,
            'amount'         => $newAmount,
            'currency'       => $item->price->currency,
            'effective_from' => null,
            'effective_to'   => null,
            'created_by'     => $manager->id,
        ]);

        $item->update(['effective_to' => $changeOn->toDateString()]);

        ContractItem::query()->create([
            'contract_id'    => $item->contract_id,
            'item_type'      => $item->item_type,
            'item_id'        => $item->item_id,
            'price_id'       => $contractPrice->id,
            'effective_from' => $changeOn->toDateString(),
            'effective_to'   => null,
            'supersedes_id'  => $item->id,
            'change_reason'  => ContractItemChangeReason::RateChange,
            'tax_rate_id'    => $item->tax_rate_id,
            'tax_rate_snapshot' => $item->tax_rate_snapshot,
        ]);
    }

    /**
     * @param  Collection<int, Unit>  $pool
     * @param  Collection<int, Contact>  $contacts
     */
    private function seedEdgeCasesForSite(
        Site $site,
        Collection $pool,
        Collection $contacts,
        object $billing,
        Employee $manager,
    ): void {
        $today = SiteClock::today($site);
        $isLondon = $site->timezone === 'Europe/London';
        $isMadrid = $site->timezone === 'Europe/Madrid';

        // Contract lifecycle statuses (S02-01): pending, notice_given, cancelled
        // (active + ended already covered by open/closed occupancy seeds)
        if ($this->needsEdge('contract_status_pending') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $contract = $this->createOpenOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->addDays(21),
            );
            $contract->forceFill(['status' => ContractStatus::Pending])->save();
            $this->edgeCases['contract_status_pending'] = $unit->unit_number;
            $this->stateCounts['occupied']++;
        }

        if ($this->needsEdge('contract_status_notice_given') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $contract = $this->createOpenOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->subMonths(2),
            );
            $moveOut = $today->addDays(14);
            $contract->forceFill([
                'status' => ContractStatus::NoticeGiven,
                'notice_given_on' => $today->toDateString(),
                'scheduled_move_out_on' => $moveOut->toDateString(),
            ])->save();
            UnitOccupancy::query()
                ->where('contract_id', $contract->id)
                ->whereNull('ended_on')
                ->update(['ended_on' => $moveOut->toDateString()]);
            $this->edgeCases['contract_status_notice_given'] = $unit->unit_number;
            $this->stateCounts['occupied']++;
        }

        // Vacate settlement policies (S02-02)
        foreach ([
            'vacate_policy_none' => MoveOutSettlement::None,
            'vacate_policy_daily' => MoveOutSettlement::Daily,
            'vacate_policy_notice_based' => MoveOutSettlement::NoticeBased,
        ] as $edgeKey => $policy) {
            if ($this->needsEdge($edgeKey) && $pool->isNotEmpty()) {
                $unit = $pool->shift();
                $endedOn = $today->subDays(10);
                $contract = $this->createEndedOccupancy(
                    $unit,
                    $contacts->random(),
                    $billing,
                    $manager,
                    $today->subMonths(4),
                    $endedOn,
                );
                $contract->forceFill([
                    'move_out_settlement' => $policy,
                    'notice_given_on' => $endedOn->subDays(14)->toDateString(),
                    'scheduled_move_out_on' => $endedOn->toDateString(),
                ])->save();
                ContractItem::query()
                    ->where('contract_id', $contract->id)
                    ->whereNull('effective_to')
                    ->update(['effective_to' => $endedOn->toDateString()]);
                $this->seedDepositRelease($contract, $manager);
                $this->edgeCases[$edgeKey] = $unit->unit_number;
                $this->stateCounts['available']++;
            }
        }

        if ($this->needsEdge('vacate_with_deductions') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $endedOn = $today->subDays(5);
            $contract = $this->createEndedOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->subMonths(3),
                $endedOn,
            );
            $contract->forceFill([
                'move_out_settlement' => MoveOutSettlement::None,
                'deposit_amount' => '200.00',
            ])->save();
            ContractItem::query()
                ->where('contract_id', $contract->id)
                ->whereNull('effective_to')
                ->update(['effective_to' => $endedOn->toDateString()]);
            $this->seedDepositWithDeduction($contract, $manager, '75.00', 'Door damage');
            $this->edgeCases['vacate_with_deductions'] = $unit->unit_number;
            $this->stateCounts['available']++;
        }

        if ($this->needsEdge('vacate_turnover_hold') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $endedOn = $today->subDays(1);
            $contract = $this->createEndedOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->subMonths(2),
                $endedOn,
            );
            ContractItem::query()
                ->where('contract_id', $contract->id)
                ->whereNull('effective_to')
                ->update(['effective_to' => $endedOn->toDateString()]);
            $holdEnd = $endedOn->addDays(3);
            $this->createManualHold(
                $unit,
                HoldType::Maintenance,
                $endedOn,
                $holdEnd,
                'post_move_out_turnover',
                $manager,
            );
            $this->edgeCases['vacate_turnover_hold'] = $unit->unit_number;
            $this->stateCounts['maintenance']++;
        }

        // Transfer: one contract, closed origin occupancy + open destination (S02-03)
        if ($this->needsEdge('transferred') && $pool->count() >= 2) {
            $fromUnit = $pool->shift();
            $toUnit = $pool->shift();
            $transferDate = $today->subDays(7);
            $contract = $this->createOpenOccupancy(
                $fromUnit,
                $contacts->random(),
                $billing,
                $manager,
                $today->subMonths(3),
            );
            $this->seedTransferredContract($contract, $fromUnit, $toUnit, $transferDate, $manager);
            $this->edgeCases['transferred'] = $toUnit->unit_number;
            $this->stateCounts['occupied']++;
            // fromUnit returned to available
            $this->stateCounts['available'] = ($this->stateCounts['available'] ?? 0) + 1;
        }

        if ($this->needsEdge('contract_status_cancelled') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $contract = $this->createOpenOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->addDays(30),
            );
            UnitOccupancy::query()->where('contract_id', $contract->id)->delete();
            $contract->forceFill([
                'status' => ContractStatus::Cancelled,
                'ended_reason' => ContractEndedReason::Cancelled,
            ])->save();
            $this->edgeCases['contract_status_cancelled'] = $unit->unit_number;
            $this->stateCounts['available']++;
        }

        // Closed occupancy → currently available
        if ($this->needsEdge('closed_occupancy') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $this->createEndedOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->subMonths(6),
                $today->subDays(30),
            );
            $this->edgeCases['closed_occupancy'] = $unit->unit_number;
            $this->stateCounts['available']++;
        }

        // Two sequential closed occupancies
        if ($this->needsEdge('two_sequential_closed') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $this->createEndedOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->subMonths(12),
                $today->subMonths(8),
            );
            $this->createEndedOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->subMonths(8),
                $today->subMonths(2),
            );
            $this->edgeCases['two_sequential_closed'] = $unit->unit_number;
            $this->stateCounts['available']++;
        }

        // Ended yesterday + new occupancy starting today (exclusive-end boundary)
        if ($this->needsEdge('exclusive_end_boundary') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $this->createEndedOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->subMonths(3),
                $today,
            );
            $this->createOpenOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today,
            );
            $this->edgeCases['exclusive_end_boundary'] = $unit->unit_number;
            $this->stateCounts['occupied']++;
        }

        // Expired reservation hold (ends_on in the past, released_at null)
        if ($this->needsEdge('expired_reservation_hold') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $this->createReservationHold(
                $unit,
                $contacts->random(),
                $today->subDays(20),
                $today->subDays(5),
                $today->subDays(6)->setTime(12, 0),
            );
            $this->edgeCases['expired_reservation_hold'] = $unit->unit_number;
            $this->stateCounts['available']++;
        }

        // Released hold → available
        if ($this->needsEdge('released_hold') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            OccupancyGuard::assertVacant($unit->id, $today->subDays(10), null);
            HoldGuard::assertUnheld($unit->id, $today->subDays(10), null);
            UnitHold::query()->create([
                'unit_id' => $unit->id,
                'hold_type' => HoldType::Maintenance,
                'starts_on' => $today->subDays(10)->format('Y-m-d'),
                'ends_on' => null,
                'released_at' => $today->subDays(2)->toDateTimeString(),
                'reason' => 'Completed repair',
                'created_by' => $manager->id,
            ]);
            $this->edgeCases['released_hold'] = $unit->unit_number;
            $this->stateCounts['available']++;
        }

        // Overlocked occupied
        if ($this->needsEdge('overlocked_occupied') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $this->createOpenOccupancy($unit, $contacts->random(), $billing, $manager, $today->subMonths(2));
            UnitHold::query()->create([
                'unit_id' => $unit->id,
                'hold_type' => HoldType::Overlock,
                'starts_on' => $today->subDays(3)->format('Y-m-d'),
                'ends_on' => null,
                'reason' => null,
                'created_by' => $manager->id,
            ]);
            $this->edgeCases['overlocked_occupied'] = $unit->unit_number;
            $this->stateCounts['occupied']++;
            $this->stateCounts['overlock']++;
        }

        // Open-ended maintenance
        if ($this->needsEdge('open_ended_maintenance') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $this->createManualHold($unit, HoldType::Maintenance, $today, null, 'Roof leak', $manager);
            $this->edgeCases['open_ended_maintenance'] = $unit->unit_number;
            $this->stateCounts['maintenance']++;
        }

        // GBP-denominated occupied (London site only)
        if ($isLondon && $this->needsEdge('gbp_occupied') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $this->createOpenOccupancy($unit, $contacts->random(), $billing, $manager, $today->subMonths(1));
            $this->edgeCases['gbp_occupied'] = $unit->unit_number;
            $this->stateCounts['occupied']++;
        }

        // Late-evening expires_at on London site — proves site-local date conversion.
        // Instant is 00:30 Madrid = 23:30 previous civil day in London (GMT winter).
        if ($isLondon && $this->needsEdge('late_evening_reservation') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $expiresAt = CarbonImmutable::parse('2026-03-15 00:30:00', 'Europe/Madrid');
            $startsOn = SiteClock::dateAt($site, $expiresAt)->subDays(10);
            $endsOn = SiteClock::dateAt($site, $expiresAt)->addDay();
            $this->createReservationHold(
                $unit,
                $contacts->random(),
                $startsOn,
                $endsOn,
                $expiresAt,
            );
            $this->edgeCases['late_evening_reservation'] = $unit->unit_number;
            // Historical fixture — hold has expired by "today"; unit is available.
            $this->stateCounts['available']++;
        }

        // Active future reservation with late-evening expiry (still reserved today).
        if ($isLondon && $this->needsEdge('late_evening_active_reservation') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $expiresAt = $today->addDays(14)->setTime(23, 30);
            $startsOn = $today;
            $endsOn = SiteClock::dateAt($site, $expiresAt)->addDay();
            $this->createReservationHold(
                $unit,
                $contacts->random(),
                $startsOn,
                $endsOn,
                $expiresAt,
            );
            $this->edgeCases['late_evening_active_reservation'] = $unit->unit_number;
            $this->stateCounts['reserved']++;
        }

        // ES site counterpart with the same absolute instant (Madrid civil date differs).
        if ($isMadrid && $this->needsEdge('es_evening_counterpart') && $pool->isNotEmpty()) {
            $unit = $pool->shift();
            $expiresAt = CarbonImmutable::parse('2026-03-15 00:30:00', 'Europe/Madrid');
            $startsOn = SiteClock::dateAt($site, $expiresAt)->subDays(10);
            $endsOn = SiteClock::dateAt($site, $expiresAt)->addDay();
            $this->createReservationHold(
                $unit,
                $contacts->random(),
                $startsOn,
                $endsOn,
                $expiresAt,
            );
            $this->edgeCases['es_evening_counterpart'] = $unit->unit_number;
            $this->stateCounts['available']++;
        }
    }

    /**
     * @param  Collection<int, Unit>  $pool
     * @param  Collection<int, Contact>  $contacts
     */
    private function seedDistributionForSite(
        Site $site,
        Collection $pool,
        Collection $contacts,
        object $billing,
        Employee $manager,
    ): void {
        $today = SiteClock::today($site);
        $remaining = $pool->count();

        $nOccupied = (int) floor($remaining * self::SHARE_OCCUPIED);
        $nReserved = (int) floor($remaining * self::SHARE_RESERVED);
        $nMaintenance = (int) floor($remaining * self::SHARE_MAINTENANCE);
        $nDamaged = (int) floor($remaining * self::SHARE_DAMAGED);
        $nStaff = (int) floor($remaining * self::SHARE_STAFF_USE);
        $nOther = (int) floor($remaining * self::SHARE_OTHER);

        $occupiedUnits = collect();

        for ($i = 0; $i < $nOccupied && $pool->isNotEmpty(); $i++) {
            $unit = $pool->shift();
            $this->createOpenOccupancy(
                $unit,
                $contacts->random(),
                $billing,
                $manager,
                $today->subDays(fake()->numberBetween(30, 365)),
            );
            $occupiedUnits->push($unit);
            $this->stateCounts['occupied']++;
        }

        if ($occupiedUnits->isNotEmpty()) {
            $nOverlock = max(1, (int) floor($occupiedUnits->count() * self::SHARE_OVERLOCK_OF_OCCUPIED));
            foreach ($occupiedUnits->take($nOverlock) as $unit) {
                UnitHold::query()->create([
                    'unit_id' => $unit->id,
                    'hold_type' => HoldType::Overlock,
                    'starts_on' => $today->subDays(1)->format('Y-m-d'),
                    'ends_on' => null,
                    'reason' => null,
                    'created_by' => $manager->id,
                ]);
                $this->stateCounts['overlock']++;
            }
        }

        for ($i = 0; $i < $nReserved && $pool->isNotEmpty(); $i++) {
            $unit = $pool->shift();
            $expiresAt = $today->addDays(fake()->numberBetween(7, 21))->setTime(12, 0);
            $startsOn = $today;
            $endsOn = SiteClock::dateAt($site, $expiresAt)->addDay();
            $this->createReservationHold($unit, $contacts->random(), $startsOn, $endsOn, $expiresAt);
            $this->stateCounts['reserved']++;
        }

        for ($i = 0; $i < $nMaintenance && $pool->isNotEmpty(); $i++) {
            $unit = $pool->shift();
            $ends = fake()->boolean(50) ? $today->addDays(14) : null;
            $this->createManualHold($unit, HoldType::Maintenance, $today, $ends, 'Scheduled maintenance', $manager);
            $this->stateCounts['maintenance']++;
        }

        for ($i = 0; $i < $nDamaged && $pool->isNotEmpty(); $i++) {
            $unit = $pool->shift();
            $this->createManualHold($unit, HoldType::Damaged, $today, null, 'Water damage', $manager);
            $this->stateCounts['damaged']++;
        }

        for ($i = 0; $i < $nStaff && $pool->isNotEmpty(); $i++) {
            $unit = $pool->shift();
            $this->createManualHold($unit, HoldType::StaffUse, $today, $today->addMonths(1), 'Manager storage', $manager);
            $this->stateCounts['staff_use']++;
        }

        for ($i = 0; $i < $nOther && $pool->isNotEmpty(); $i++) {
            $unit = $pool->shift();
            $this->createManualHold($unit, HoldType::Other, $today, null, 'Legal hold', $manager);
            $this->stateCounts['other']++;
        }

        $this->stateCounts['available'] += $pool->count();
    }

    private function needsEdge(string $key): bool
    {
        return ! isset($this->edgeCases[$key]);
    }

    private function createOpenOccupancy(
        Unit $unit,
        Contact $contact,
        object $billing,
        Employee $manager,
        CarbonImmutable $moveIn,
    ): Contract {
        return $this->createOccupancyContract($unit, $contact, $billing, $manager, $moveIn, null);
    }

    private function createEndedOccupancy(
        Unit $unit,
        Contact $contact,
        object $billing,
        Employee $manager,
        CarbonImmutable $startedOn,
        CarbonImmutable $endedOn,
    ): Contract {
        return $this->createOccupancyContract($unit, $contact, $billing, $manager, $startedOn, $endedOn);
    }

    private function createOccupancyContract(
        Unit $unit,
        Contact $contact,
        object $billing,
        Employee $manager,
        CarbonImmutable $moveIn,
        ?CarbonImmutable $endedOn,
    ): Contract {
        $unit->loadMissing('site');
        $rate = UnitClassRate::query()
            ->with('price')
            ->where('site_id', $unit->site_id)
            ->where('unit_class_id', $unit->unit_class_id)
            ->firstOrFail();

        /** @var Price $price */
        $price = $rate->price;

        $plan = ContractBilling::planFirstPeriod(
            $moveIn,
            $billing->billingAnchorModel,
            $billing->defaultBillingInterval,
            $billing->defaultBillingIntervalCount,
            $billing->billingAnchorDay,
        );

        $items = collect([[
            'currency' => $price->currency,
        ]]);
        $currency = CurrencyGuard::assertItemsAgree($items);

        OccupancyGuard::assertVacant($unit->id, $moveIn, $endedOn);

        $contract = Contract::query()->create([
            'contact_id'             => $contact->id,
            'start_date'             => $moveIn->format('Y-m-d'),
            'end_date'               => $endedOn?->format('Y-m-d'),
            'status'                 => $endedOn !== null ? ContractStatus::Ended : ContractStatus::Active,
            'ended_reason'           => $endedOn !== null ? ContractEndedReason::Vacated : null,
            'move_out_on'            => $endedOn?->format('Y-m-d'),
            'notice_period_days'     => Setting::leasing()->defaultNoticePeriodDays,
            'move_out_settlement'    => $billing->moveOutSettlement ?? MoveOutSettlement::None->value,
            'transfer_billing'       => $billing->transferBilling ?? 'prorate_immediately',
            'signed_at'              => $moveIn,
            'billing_interval'       => $billing->defaultBillingInterval,
            'billing_interval_count' => $billing->defaultBillingIntervalCount,
            'billing_anchor_model'   => $billing->billingAnchorModel,
            'billing_anchor_date'    => $plan->anchorDate->toDateString(),
            'billed_through'         => $plan->billedThrough->toDateString(),
            'proration_method'       => $billing->prorationMethod,
            'move_in_date'           => $moveIn->format('Y-m-d'),
            'deposit_amount'         => $billing->defaultDepositAmount,
            'currency'               => $currency,
        ]);

        $item = ContractItem::query()->create([
            'contract_id'    => $contract->id,
            'item_type'      => 'unit',
            'item_id'        => $unit->id,
            'price_id'       => $price->id,
            'effective_from' => $moveIn->format('Y-m-d'),
            'effective_to'   => $endedOn?->format('Y-m-d'),
            'change_reason'  => null,
        ]);

        UnitOccupancy::query()->create([
            'unit_id'          => $unit->id,
            'contract_id'      => $contract->id,
            'contract_item_id' => $item->id,
            'started_on'       => $moveIn->format('Y-m-d'),
            'ended_on'         => $endedOn?->format('Y-m-d'),
            'ended_reason'     => $endedOn !== null ? ContractEndedReason::Vacated->value : null,
            'created_by'       => $manager->id,
        ]);

        return $contract;
    }

    private function seedTransferredContract(
        Contract $contract,
        Unit $fromUnit,
        Unit $toUnit,
        CarbonImmutable $transferDate,
        Employee $manager,
    ): void {
        $toUnit->loadMissing('site');
        OccupancyGuard::assertVacant($toUnit->id, $transferDate, null);
        HoldGuard::assertUnheld($toUnit->id, $transferDate, null);

        $rate = UnitClassRate::query()
            ->with('price')
            ->where('site_id', $toUnit->site_id)
            ->where('unit_class_id', $toUnit->unit_class_id)
            ->firstOrFail();

        /** @var Price $destPrice */
        $destPrice = $rate->price;

        $originItem = ContractItem::query()
            ->where('contract_id', $contract->id)
            ->where('item_type', 'unit')
            ->whereNull('effective_to')
            ->firstOrFail();

        UnitOccupancy::query()
            ->where('contract_id', $contract->id)
            ->where('unit_id', $fromUnit->id)
            ->update([
                'ended_on' => $transferDate->toDateString(),
                'ended_reason' => ContractEndedReason::TransferredOut->value,
            ]);

        $originItem->forceFill([
            'effective_to' => $transferDate->toDateString(),
        ])->save();

        $newItem = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $toUnit->id,
            'price_id' => $destPrice->id,
            'effective_from' => $transferDate->toDateString(),
            'effective_to' => null,
            'supersedes_id' => $originItem->id,
            'change_reason' => ContractItemChangeReason::Transfer,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $toUnit->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $newItem->id,
            'started_on' => $transferDate->toDateString(),
            'ended_on' => null,
            'created_by' => $manager->id,
        ]);

        ContractTransfer::query()->create([
            'contract_id' => $contract->id,
            'from_unit_id' => $fromUnit->id,
            'to_unit_id' => $toUnit->id,
            'from_contract_item_id' => $originItem->id,
            'to_contract_item_id' => $newItem->id,
            'transfer_date' => $transferDate->toDateString(),
            'pricing_mode' => TransferPricingMode::DestinationRate,
            'reason' => 'Seeded upsell transfer',
            'created_by' => $manager->id,
        ]);
    }

    private function seedDepositRelease(Contract $contract, Employee $manager): void
    {
        if (bccomp((string) $contract->deposit_amount, '0', 2) <= 0) {
            $contract->forceFill(['deposit_amount' => '100.00'])->save();
        }

        $refundNet = bcmul((string) $contract->deposit_amount, '-1', 2);
        $charge = Charge::query()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Refund,
            'net_amount' => $refundNet,
            'tax_rate_snapshot' => null,
            'tax_amount' => '0.00',
            'amount' => $refundNet,
            'currency' => $contract->currency,
            'due_date' => $contract->move_out_on?->toDateString() ?? now()->toDateString(),
            'description' => 'vacate.deposit_refund: deposit.release',
        ]);

        $settlement = DepositSettlement::query()->create([
            'contract_id' => $contract->id,
            'outcome' => DepositSettlementOutcome::Released,
            'deposit_amount' => $contract->deposit_amount,
            'refunded_amount' => $contract->deposit_amount,
            'currency' => $contract->currency,
            'payout_status' => DepositPayoutStatus::Pending,
            'created_by' => $manager->id,
        ]);

        DepositSettlementLine::query()->create([
            'deposit_settlement_id' => $settlement->id,
            'charge_id' => $charge->id,
            'amount' => $contract->deposit_amount,
            'currency' => $contract->currency,
            'reason' => 'deposit.release',
            'created_at' => now(),
        ]);
    }

    private function seedDepositWithDeduction(
        Contract $contract,
        Employee $manager,
        string $deductionAmount,
        string $reason,
    ): void {
        $deposit = BillingMath::round2((string) $contract->deposit_amount);
        $deduction = BillingMath::round2($deductionAmount);
        $remainder = bcsub($deposit, $deduction, 2);

        $adj = Charge::query()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Adjustment,
            'net_amount' => $deduction,
            'tax_rate_snapshot' => '0.00',
            'tax_amount' => '0.00',
            'amount' => $deduction,
            'currency' => $contract->currency,
            'due_date' => $contract->move_out_on?->toDateString() ?? now()->toDateString(),
            'description' => 'vacate.deposit_deduction: '.$reason,
        ]);

        $settlement = DepositSettlement::query()->create([
            'contract_id' => $contract->id,
            'outcome' => DepositSettlementOutcome::Deducted,
            'deposit_amount' => $deposit,
            'refunded_amount' => $remainder,
            'currency' => $contract->currency,
            'payout_status' => DepositPayoutStatus::Pending,
            'created_by' => $manager->id,
        ]);

        DepositSettlementLine::query()->create([
            'deposit_settlement_id' => $settlement->id,
            'charge_id' => $adj->id,
            'amount' => $deduction,
            'currency' => $contract->currency,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        if (bccomp($remainder, '0', 2) === 1) {
            $refundNet = bcmul($remainder, '-1', 2);
            $refund = Charge::query()->create([
                'contract_id' => $contract->id,
                'charge_type' => ChargeType::Refund,
                'net_amount' => $refundNet,
                'tax_rate_snapshot' => null,
                'tax_amount' => '0.00',
                'amount' => $refundNet,
                'currency' => $contract->currency,
                'due_date' => $contract->move_out_on?->toDateString() ?? now()->toDateString(),
                'description' => 'vacate.deposit_refund: deposit.remainder',
            ]);

            DepositSettlementLine::query()->create([
                'deposit_settlement_id' => $settlement->id,
                'charge_id' => $refund->id,
                'amount' => $remainder,
                'currency' => $contract->currency,
                'reason' => 'deposit.remainder',
                'created_at' => now(),
            ]);
        }
    }

    private function createReservationHold(
        Unit $unit,
        Contact $contact,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        CarbonImmutable $expiresAt,
    ): void {
        OccupancyGuard::assertVacant($unit->id, $startsOn, $endsOn);
        HoldGuard::assertUnheld($unit->id, $startsOn, $endsOn);

        $reservation = Reservation::query()->create([
            'unit_id'    => $unit->id,
            'contact_id' => $contact->id,
            'status'     => ReservationStatus::Confirmed,
            'expires_at' => $expiresAt,
        ]);

        UnitHold::query()->create([
            'unit_id'        => $unit->id,
            'hold_type'      => HoldType::Reservation,
            'reservation_id' => $reservation->id,
            'starts_on'      => $startsOn->format('Y-m-d'),
            'ends_on'        => $endsOn->format('Y-m-d'),
            'released_at'    => null,
            'reason'         => null,
        ]);
    }

    private function createManualHold(
        Unit $unit,
        HoldType $type,
        CarbonImmutable $startsOn,
        ?CarbonImmutable $endsOn,
        string $reason,
        Employee $manager,
    ): void {
        OccupancyGuard::assertVacant($unit->id, $startsOn, $endsOn);
        HoldGuard::assertUnheld($unit->id, $startsOn, $endsOn);

        UnitHold::query()->create([
            'unit_id'     => $unit->id,
            'hold_type'   => $type,
            'starts_on'   => $startsOn->format('Y-m-d'),
            'ends_on'     => $endsOn?->format('Y-m-d'),
            'released_at' => null,
            'reason'      => $reason,
            'created_by'  => $manager->id,
        ]);
    }

    /** @param  Collection<int, Site>  $sites */
    private function printSummary(Collection $sites): void
    {
        $this->command?->info('=== Occupancy / hold seed summary ===');
        $this->command?->table(
            ['State', 'Count'],
            collect($this->stateCounts)->map(fn ($count, $state) => [$state, $count])->values()->all(),
        );

        $this->command?->table(
            ['Site', 'Currency', 'Country', 'Timezone'],
            $sites->map(fn (Site $site) => [
                $site->name,
                $site->currency,
                $site->country?->code,
                $site->timezone,
            ])->all(),
        );

        $this->command?->table(
            ['Edge case', 'Unit number'],
            collect($this->edgeCases)->map(fn ($number, $key) => [$key, $number])->values()->all(),
        );
    }
}
