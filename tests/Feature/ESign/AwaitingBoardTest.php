<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Enums\ContractStatus;
use App\Enums\EsignEnvelopeStatus;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\EsignEnvelope;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ESign\CreatesEnvelopeFixtures;
use Tests\TestCase;

class AwaitingBoardTest extends TestCase
{
    use CreatesEnvelopeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvelopeWorld();
    }

    public function test_tab_counts_aging(): void
    {
        [$contractSoon, $documentSoon] = $this->prepareAwaitingWithDocument(
            $this->contactWithEmail('soon@example.com')
        );
        $sentSoon = $this->sendEnvelope($contractSoon, $documentSoon->id);
        $envelopeSoon = EsignEnvelope::query()->findOrFail($sentSoon['id']);
        $envelopeSoon->forceFill([
            'expires_at' => CarbonImmutable::now()->addDays(2),
            'sent_at' => CarbonImmutable::now()->subDays(3),
        ])->save();

        [$contractLater, $documentLater] = $this->prepareAwaitingOnUnit(
            Unit::factory()->create([
                'site_id' => $this->site->id,
                'unit_class_id' => $this->unit->unit_class_id,
                'unit_number' => 'A-102',
            ]),
            $this->contactWithEmail('later@example.com')
        );
        $sentLater = $this->sendEnvelope($contractLater, $documentLater->id);
        $envelopeLater = EsignEnvelope::query()->findOrFail($sentLater['id']);
        $envelopeLater->forceFill([
            'expires_at' => CarbonImmutable::now()->addDays(11),
            'sent_at' => CarbonImmutable::now()->subDay(),
            'viewed_at' => CarbonImmutable::now()->subHours(6),
            'status' => EsignEnvelopeStatus::Viewed,
        ])->save();

        $walkInUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unit->unit_class_id,
            'unit_number' => 'A-103',
        ]);
        $this->postJson('/api/contracts', [
            'contact_id' => $this->contactWithEmail('walkin@example.com')->id,
            'start_date' => '2026-08-01',
            'move_in_date' => '2026-08-01',
            'deposit_amount' => '0.00',
            'signature_mode' => 'immediate',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $walkInUnit->id,
                'amount' => '100.00',
            ]],
        ])->assertCreated();

        $response = $this->getJson('/api/contracts/board?per_column=30');
        $response->assertOk();

        $columns = collect($response->json('data.columns'));
        $awaiting = $columns->firstWhere('status', ContractStatus::AwaitingSignature->value);

        $this->assertNotNull($awaiting);
        $this->assertSame(2, $awaiting['total']);
        $this->assertCount(2, $awaiting['cards']);

        $byId = collect($awaiting['cards'])->keyBy('id');

        $soonCard = $byId->get($contractSoon->id);
        $this->assertNotNull($soonCard['envelope']);
        $this->assertSame('sent', $soonCard['envelope']['status']);
        $this->assertTrue($soonCard['envelope']['expiring_soon']);
        $this->assertNotNull($soonCard['envelope']['sent_at']);
        $this->assertNotNull($soonCard['envelope']['expires_at']);

        $laterCard = $byId->get($contractLater->id);
        $this->assertNotNull($laterCard['envelope']);
        $this->assertSame('viewed', $laterCard['envelope']['status']);
        $this->assertFalse($laterCard['envelope']['expiring_soon']);
        $this->assertNotNull($laterCard['envelope']['viewed_at']);
    }

    /** @return array{0: Contract, 1: ContractDocument} */
    private function prepareAwaitingOnUnit(Unit $unit, $contact): array
    {
        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-08-01',
            'move_in_date' => '2026-08-01',
            'deposit_amount' => '200.00',
            'signature_mode' => 'remote',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '125.50',
            ]],
        ]);
        $response->assertCreated();
        $contract = Contract::query()->findOrFail((int) $response->json('data.id'));

        $create = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'locale' => 'en',
        ]);
        $create->assertCreated();
        $document = ContractDocument::query()->findOrFail((int) $create->json('data.id'));

        return [$contract, $document];
    }
}
