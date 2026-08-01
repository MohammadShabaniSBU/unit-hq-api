<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ContractNoticeType;
use App\Enums\ContractStatus;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractNotice;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ContractNoticeTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = $price->id;
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rate_change_retrofit(): void
    {
        Sanctum::actingAs($this->employee);

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
            'rate_change_notice_days' => 30,
        ]);

        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);

        $response = $this->postJson("/api/contracts/{$contract->id}/rate-changes", [
            'contract_item_id' => $item->id,
            'new_amount' => '125.00',
            'effective_date' => '2026-09-20',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.notice.notice_type', ContractNoticeType::RateChange->value);
        $response->assertJsonPath('data.notice.effective_date', '2026-09-20');
        $response->assertJsonPath('data.notice.required_by', '2026-09-14');
        $response->assertJsonPath('data.item.amount', '125.00');
        $response->assertJsonPath('data.item.change_reason', 'rate_change');
        $response->assertJsonPath('data.previous_item.effective_to', '2026-09-20');

        $this->assertSame(1, ContractNotice::query()->where('contract_id', $contract->id)->count());
        $notice = ContractNotice::query()->where('contract_id', $contract->id)->first();
        $this->assertSame(ContractNoticeType::RateChange, $notice?->notice_type);
        $this->assertSame($response->json('data.item.id'), $notice?->contract_item_id);

        $item->refresh();
        $this->assertSame('2026-09-20', $item->effective_to?->toDateString());
    }
}
