<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\LogChannel;
use App\Enums\PaymentMethod;
use App\Models\Activity;
use App\Models\Allocation;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Contract $contract;

    private Charge $olderCharge;

    private Charge $newerCharge;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);

        $this->contract = Contract::factory()->create(['currency' => 'EUR']);

        $this->olderCharge = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
            'description' => 'Older overdue',
        ]);
        $this->newerCharge = Charge::factory()->create([
            'contract_id' => $this->contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '80.00',
            'net_amount' => '80.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-15',
            'description' => 'Newer overdue',
        ]);
    }

    public function test_records_with_causer_and_method(): void
    {
        $response = $this->postJson("/api/contracts/{$this->contract->id}/payments", [
            'amount' => '150.00',
            'method' => 'cash',
            'received_on' => '2026-08-02',
            'reference' => 'RCPT-1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.method', 'cash')
            ->assertJsonPath('data.received_on', '2026-08-02')
            ->assertJsonPath('data.reference', 'RCPT-1')
            ->assertJsonPath('data.amount', '150.00')
            ->assertJsonPath('data.currency', 'EUR');

        $payment = Payment::query()->findOrFail($response->json('data.id'));
        $this->assertSame(PaymentMethod::Cash, $payment->method);
        $this->assertNull($payment->stripe_payment_intent_id);
        $this->assertStringStartsWith('manual:', $payment->idempotency_key);

        $activity = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'payment.recorded')
            ->where('subject_id', $payment->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($this->employee->id, $activity->causer_id);
        $this->assertSame('150.00', $activity->properties->get('amount'));
        $this->assertSame('cash', $activity->properties->get('method'));
        $this->assertSame('RCPT-1', $activity->properties->get('reference'));
        $this->assertIsString($activity->properties->get('amount'));
    }

    public function test_auto_allocates_oldest_due_first(): void
    {
        $response = $this->postJson("/api/contracts/{$this->contract->id}/payments", [
            'amount' => '120.00',
            'method' => 'cash',
            'received_on' => '2026-08-02',
        ]);

        $response->assertCreated();
        $paymentId = (int) $response->json('data.id');

        $allocations = Allocation::query()
            ->where('payment_id', $paymentId)
            ->get()
            ->keyBy('charge_id');

        $this->assertCount(2, $allocations);
        $this->assertSame('100.00', (string) $allocations[$this->olderCharge->id]->amount);
        $this->assertSame('20.00', (string) $allocations[$this->newerCharge->id]->amount);
    }

    public function test_over_allocation_rejected(): void
    {
        $this->postJson("/api/contracts/{$this->contract->id}/payments", [
            'amount' => '50.00',
            'method' => 'bank_transfer',
            'received_on' => '2026-08-02',
            'allocations' => [
                ['charge_id' => $this->olderCharge->id, 'amount' => '60.00'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['allocations']);

        $this->postJson("/api/contracts/{$this->contract->id}/payments", [
            'amount' => '150.00',
            'method' => 'bank_transfer',
            'received_on' => '2026-08-02',
            'allocations' => [
                ['charge_id' => $this->olderCharge->id, 'amount' => '120.00'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['allocations']);

        $this->assertSame(0, Payment::query()->where('contract_id', $this->contract->id)->count());
    }

    public function test_remainder_is_computed_credit(): void
    {
        $this->postJson("/api/contracts/{$this->contract->id}/payments", [
            'amount' => '200.00',
            'method' => 'cash',
            'received_on' => '2026-08-02',
        ])->assertCreated();

        $this->contract->refresh();
        $this->assertSame('-20.00', $this->contract->balanceOwed());
        $this->assertSame('20.00', $this->contract->unallocatedCredit());

        $this->assertFalse(Schema::hasColumn('payments', 'unallocated_credit'));
        $this->assertFalse(Schema::hasColumn('contracts', 'unallocated_credit'));
    }

    public function test_reversal_appends_never_edits(): void
    {
        $create = $this->postJson("/api/contracts/{$this->contract->id}/payments", [
            'amount' => '100.00',
            'method' => 'cash',
            'received_on' => '2026-08-02',
            'reference' => 'WRONG',
            'allocations' => [
                ['charge_id' => $this->olderCharge->id, 'amount' => '100.00'],
            ],
        ]);
        $create->assertCreated();
        $originalId = (int) $create->json('data.id');

        $reverse = $this->postJson("/api/payments/{$originalId}/reverse", [
            'reason' => 'Wrong amount entered',
        ]);
        $reverse->assertCreated()
            ->assertJsonPath('data.amount', '-100.00')
            ->assertJsonPath('data.reversal_of_payment_id', $originalId);

        $original = Payment::query()->findOrFail($originalId);
        $this->assertSame('100.00', (string) $original->amount);
        $this->assertSame('WRONG', $original->reference);
        $this->assertSame(1, $original->allocations()->count());
        $this->assertSame('100.00', (string) $original->allocations()->first()->amount);

        $reversal = Payment::query()->findOrFail($reverse->json('data.id'));
        $this->assertSame(1, $reversal->allocations()->count());
        $this->assertSame('-100.00', (string) $reversal->allocations()->first()->amount);

        $this->assertSame('100.00', $this->olderCharge->fresh()->openAmount());

        $activity = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'payment.reversed')
            ->where('subject_id', $reversal->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($this->employee->id, $activity->causer_id);
        $this->assertSame('Wrong amount entered', $activity->properties->get('reason'));
        $this->assertSame('-100.00', $activity->properties->get('amount'));
        $this->assertIsString($activity->properties->get('amount'));
    }

    public function test_overdue_recomputes_after_payment(): void
    {
        $this->assertSame('180.00', $this->contract->overdueAmount());

        $this->postJson("/api/contracts/{$this->contract->id}/payments", [
            'amount' => '100.00',
            'method' => 'cash',
            'received_on' => '2026-08-02',
        ])->assertCreated();

        $this->contract->refresh();
        $this->assertSame('80.00', $this->contract->overdueAmount());
    }
}
