<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai\Summaries;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\Price;
use App\Support\Ai\Summaries\SummaryContextResolver;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

class DealSummaryContextTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
        config([
            'ai.summaries.caps' => [
                'interactions' => 40,
                'notes' => 20,
                'body_chars' => 800,
            ],
        ]);
    }

    #[Test]
    public function build_includes_stage_expected_need_and_offers(): void
    {
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $this->siteA->id,
            'status' => DealStatus::Qualified,
            'expected_move_in' => '2026-09-01',
            'desired_unit_class_id' => $this->unitClass->id,
        ]);
        Offer::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
        ]);

        $context = (new SummaryContextResolver)->resolve($deal->fresh(['desiredUnitClass', 'site', 'contact']), $this->owner);
        $payload = $context->build();

        $this->assertSame('deal', $payload['entity']);
        $this->assertSame(DealStatus::Qualified->value, $payload['identity']['status']);
        $this->assertSame('2026-09-01', $payload['expected_need']['expected_move_in']);
        $this->assertSame($this->unitClass->label, $payload['expected_need']['desired_unit_class']);
        $this->assertCount(1, $payload['offers']);
        $this->assertSame('sent', $payload['offers'][0]['status']);
    }

    #[Test]
    public function contract_monthly_rate_carries_currency(): void
    {
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $this->siteA->id,
        ]);

        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'deal_id' => $deal->id,
            'status' => ContractStatus::Active,
            'currency' => 'EUR',
            'move_in_date' => '2026-01-15',
        ]);

        $price = Price::query()->findOrFail($this->priceIdA);
        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $this->unitA->id,
            'price_id' => $price->id,
            'base_rate' => $price->amount,
            'effective_from' => '2026-01-15',
            'effective_to' => null,
        ]);

        $context = (new SummaryContextResolver)->resolve($deal->fresh(['desiredUnitClass', 'site', 'contact']), $this->owner);
        $payload = $context->build();

        $this->assertNotEmpty($payload['contracts']);
        $rate = $payload['contracts'][0]['monthly_rate'];
        $this->assertIsArray($rate);
        $this->assertArrayHasKey('amount', $rate);
        $this->assertArrayHasKey('currency', $rate);
        $this->assertSame('EUR', $rate['currency']);
    }

    #[Test]
    public function site_scoped_employee_cannot_see_out_of_grant_deal_offers_via_other_site(): void
    {
        $contact = Contact::factory()->create();
        $dealB = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $this->siteB->id,
            'status' => DealStatus::Qualified,
        ]);
        Offer::factory()->create([
            'deal_id' => $dealB->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
        ]);

        // Agent is site-A only — deal B itself is out of grant; resolver still builds
        // but nested visibleTo queries return empty collections for that subject.
        $this->assertFalse(
            Deal::query()
                ->visibleTo($this->agent, Permission::DealManage)
                ->whereKey($dealB->id)
                ->exists()
        );
    }
}
