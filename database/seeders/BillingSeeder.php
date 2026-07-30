<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ContractStatus;
use App\Models\Allocation;
use App\Enums\ChargeType;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BillingSeeder extends Seeder
{
    use WithoutModelEvents;

    /** @var array<int, array{charge: Charge, remaining: float}> */
    private array $chargeQueue = [];

    /** @var Collection<int, Charge> */
    private Collection $createdCharges;

    /** @var Collection<int, Payment> */
    private Collection $createdPayments;

    public function run(): void
    {
        $this->createdCharges = collect();
        $this->createdPayments = collect();

        Contract::query()
            ->with('items')
            ->where('status', ContractStatus::Active)
            ->each(function (Contract $contract): void {
                $unitItem = $contract->items->firstWhere('item_type', 'unit');

                if ($unitItem === null) {
                    return;
                }

                $this->chargeQueue = [];
                $this->createdCharges = collect();
                $this->createdPayments = collect();

                $profile = $this->assignPayerProfile();
                $periods = $this->buildBillingPeriods(Carbon::parse($contract->start_date));
                $insuranceItem = $contract->items->firstWhere('item_type', 'insurance');
                $unpaidTailSize = $profile === 'late' ? fake()->numberBetween(1, 2) : 0;
                $delinquentCutoff = $profile === 'delinquent'
                    ? max(0, count($periods) - fake()->numberBetween(2, 3))
                    : 0;

                foreach ($periods as $index => $period) {
                    $isRecentPeriod = $unpaidTailSize > 0 && $index >= count($periods) - $unpaidTailSize;

                    $invoice = $this->createInvoice($contract, $period);
                    $periodCharges = $this->createPeriodCharges(
                        $contract,
                        $invoice,
                        $unitItem,
                        $insuranceItem,
                        $period
                    );

                    $periodTotal = $periodCharges->sum(fn (Charge $charge) => (float) $charge->amount);

                    if ($periodTotal <= 0) {
                        continue;
                    }

                    if ($this->shouldPayForPeriod($profile, $index, count($periods), $isRecentPeriod, $delinquentCutoff)) {
                        $paymentAmount = $this->resolvePaymentAmount(
                            $profile,
                            $periodTotal,
                            $index,
                            count($periods)
                        );

                        if ($paymentAmount > 0) {
                            $paidAt = $this->resolvePaymentDate($profile, $period['due_date'], $isRecentPeriod);
                            $this->createPaymentWithAllocations($contract, $paymentAmount, $paidAt);
                        }
                    }
                }

                if (in_array($profile, ['reliable', 'late'], true) && fake()->boolean(12)) {
                    $this->maybeInsertReversal();
                }
            });
    }

    private function assignPayerProfile(): string
    {
        return fake()->randomElement([
            'reliable', 'reliable', 'reliable', 'reliable', 'reliable', 'reliable',
            'late', 'late',
            'partial',
            'delinquent',
        ]);
    }

    /**
     * @return array<int, array{start: Carbon, end: Carbon, due_date: Carbon}>
     */
    private function buildBillingPeriods(Carbon $contractStart): array
    {
        $periods = [];
        $periodStart = $contractStart->copy();
        $today = now()->startOfDay();
        $count = 0;

        while ($periodStart->lte($today) && $count < 12) {
            $periodEnd = $periodStart->copy()->addMonth()->subDay();

            if ($periodEnd->gt($today)) {
                $periodEnd = $today->copy();
            }

            $periods[] = [
                'start'    => $periodStart->copy(),
                'end'      => $periodEnd->copy(),
                'due_date' => $periodStart->copy(),
            ];

            $periodStart = $periodStart->copy()->addMonth();
            $count++;
        }

        return $periods;
    }

    /**
     * @param array{start: Carbon, end: Carbon, due_date: Carbon} $period
     */
    private function createInvoice(Contract $contract, array $period): Invoice
    {
        $issuedAt = $period['start']->copy();

        $invoice = Invoice::query()->create([
            'contract_id'          => $contract->id,
            'billing_period_start' => $period['start']->toDateString(),
            'billing_period_end'   => $period['end']->toDateString(),
            'status'               => 'issued',
            'issued_at'            => $issuedAt,
        ]);

        $invoice->forceFill(['created_at' => $issuedAt])->save();

        return $invoice;
    }

    /**
     * @param array{start: Carbon, end: Carbon, due_date: Carbon} $period
     * @return Collection<int, Charge>
     */
    private function createPeriodCharges(
        Contract $contract,
        Invoice $invoice,
        ContractItem $unitItem,
        ?ContractItem $insuranceItem,
        array $period
    ): Collection {
        $charges = collect();
        $createdAt = $period['start']->copy();

        $rentCharge = Charge::query()->create([
            'contract_id'           => $contract->id,
            'contract_item_id'      => $unitItem->id,
            'invoice_id'            => $invoice->id,
            'charge_type'           => ChargeType::Rent,
            'period_start'          => $period['start']->toDateString(),
            'period_end'            => $period['end']->toDateString(),
            'net_amount'            => $unitItem->amount,
            'amount'                => $unitItem->amount,
            'due_date'              => $period['due_date']->toDateString(),
            'description'           => 'Monthly rent',
            'reversal_of_charge_id' => null,
        ]);
        $rentCharge->forceFill(['created_at' => $createdAt])->save();
        $this->enqueueCharge($rentCharge);
        $charges->push($rentCharge);
        $this->createdCharges->push($rentCharge);

        if ($insuranceItem !== null) {
            $insuranceCharge = Charge::query()->create([
                'contract_id'           => $contract->id,
                'contract_item_id'      => $insuranceItem->id,
                'invoice_id'            => $invoice->id,
                'charge_type'           => ChargeType::Insurance,
                'period_start'          => $period['start']->toDateString(),
                'period_end'            => $period['end']->toDateString(),
                'net_amount'            => $insuranceItem->amount,
                'amount'                => $insuranceItem->amount,
                'due_date'              => $period['due_date']->toDateString(),
                'description'           => 'Monthly insurance',
                'reversal_of_charge_id' => null,
            ]);
            $insuranceCharge->forceFill(['created_at' => $createdAt])->save();
            $this->enqueueCharge($insuranceCharge);
            $charges->push($insuranceCharge);
            $this->createdCharges->push($insuranceCharge);
        }

        return $charges;
    }

    private function enqueueCharge(Charge $charge): void
    {
        $this->chargeQueue[] = [
            'charge'    => $charge,
            'remaining' => (float) $charge->amount,
        ];
    }

    private function shouldPayForPeriod(
        string $profile,
        int $periodIndex,
        int $totalPeriods,
        bool $isRecentPeriod,
        int $delinquentCutoff
    ): bool {
        return match ($profile) {
            'reliable'   => true,
            'late'       => ! $isRecentPeriod,
            'partial'    => true,
            'delinquent' => $periodIndex < $delinquentCutoff,
            default      => false,
        };
    }

    private function resolvePaymentAmount(
        string $profile,
        float $periodTotal,
        int $periodIndex,
        int $totalPeriods
    ): float {
        return match ($profile) {
            'reliable', 'late' => round($periodTotal, 2),
            'partial' => $this->resolvePartialPaymentAmount($periodTotal, $periodIndex, $totalPeriods),
            'delinquent' => round($periodTotal, 2),
            default => 0.0,
        };
    }

    private function resolvePartialPaymentAmount(float $periodTotal, int $periodIndex, int $totalPeriods): float
    {
        if ($periodIndex === $totalPeriods - 1 && fake()->boolean(30)) {
            return round($periodTotal * fake()->randomFloat(2, 1.05, 1.15), 2);
        }

        return round($periodTotal * fake()->randomFloat(2, 0.60, 0.90), 2);
    }

    private function resolvePaymentDate(string $profile, Carbon $dueDate, bool $isRecentPeriod): Carbon
    {
        if ($profile === 'late' && ! $isRecentPeriod) {
            return $dueDate->copy()->addDays(fake()->numberBetween(15, 25));
        }

        if ($profile === 'reliable') {
            return $dueDate->copy()->addDays(fake()->numberBetween(1, 5));
        }

        if ($profile === 'partial') {
            return $dueDate->copy()->addDays(fake()->numberBetween(3, 10));
        }

        return $dueDate->copy()->addDays(fake()->numberBetween(1, 7));
    }

    private function createPaymentWithAllocations(Contract $contract, float $amount, Carbon $paidAt): void
    {
        $payment = Payment::query()->create([
            'contract_id'            => $contract->id,
            'amount'                 => $amount,
            'stripe_payment_intent_id' => 'pi_' . Str::random(24),
            'idempotency_key'        => (string) Str::uuid(),
            'reversal_of_payment_id' => null,
        ]);
        $payment->forceFill(['created_at' => $paidAt])->save();
        $this->createdPayments->push($payment);

        $paymentRemaining = $amount;

        foreach ($this->chargeQueue as &$entry) {
            if ($paymentRemaining <= 0) {
                break;
            }

            if ($entry['remaining'] <= 0) {
                continue;
            }

            $allocationAmount = min($entry['remaining'], $paymentRemaining);

            $allocation = Allocation::query()->create([
                'payment_id' => $payment->id,
                'charge_id'  => $entry['charge']->id,
                'amount'     => $allocationAmount,
            ]);
            $allocation->forceFill(['created_at' => $paidAt])->save();

            $entry['remaining'] -= $allocationAmount;
            $paymentRemaining -= $allocationAmount;
        }

        unset($entry);
    }

    private function maybeInsertReversal(): void
    {
        if ($this->createdCharges->isNotEmpty() && fake()->boolean(50)) {
            $original = $this->createdCharges->random();
            $createdAt = Carbon::parse($original->created_at)->addDays(fake()->numberBetween(1, 5));

            $reversal = Charge::query()->create([
                'contract_id'           => $original->contract_id,
                'invoice_id'            => $original->invoice_id,
                'charge_type'           => $original->charge_type,
                'amount'                => bcmul((string) $original->amount, '-1', 2),
                'due_date'              => $original->due_date,
                'description'           => 'Reversal of charge #' . $original->id,
                'reversal_of_charge_id' => $original->id,
            ]);
            $reversal->forceFill(['created_at' => $createdAt])->save();

            return;
        }

        if ($this->createdPayments->isEmpty()) {
            return;
        }

        $original = $this->createdPayments->random();
        $createdAt = Carbon::parse($original->created_at)->addDays(fake()->numberBetween(1, 5));

        $reversal = Payment::query()->create([
            'contract_id'            => $original->contract_id,
            'amount'                 => bcmul((string) $original->amount, '-1', 2),
            'stripe_payment_intent_id' => null,
            'idempotency_key'        => (string) Str::uuid(),
            'reversal_of_payment_id' => $original->id,
        ]);
        $reversal->forceFill(['created_at' => $createdAt])->save();
    }
}
