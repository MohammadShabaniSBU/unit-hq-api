<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Enums\EsignEnvelopeStatus;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\EsignEnvelope;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ESign\CreatesEnvelopeFixtures;
use Tests\TestCase;

class AttentionTest extends TestCase
{
    use CreatesEnvelopeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvelopeWorld();
    }

    public function test_two_chips_filter(): void
    {
        [$declinedContract, $declinedDocument] = $this->prepareAwaitingWithDocument(
            $this->contactWithEmail('declined@example.com')
        );
        $sentDeclined = $this->sendEnvelope($declinedContract, $declinedDocument->id);
        $this->fireWebhook('declined', $sentDeclined['provider_envelope_ref'], [
            'decline_reason' => 'Not interested',
        ]);
        $declinedEnvelope = EsignEnvelope::query()->findOrFail($sentDeclined['id']);
        $this->assertSame(EsignEnvelopeStatus::Declined, $declinedEnvelope->status);

        $postCancelUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unit->unit_class_id,
            'unit_number' => 'A-201',
        ]);
        [$postCancelContract, $postCancelDocument] = $this->prepareAwaitingOnUnit(
            $postCancelUnit,
            $this->contactWithEmail('postcancel@example.com')
        );
        $sentPost = $this->sendEnvelope($postCancelContract, $postCancelDocument->id);
        $this->postJson("/api/contracts/{$postCancelContract->id}/cancel")->assertOk();
        $this->fireWebhook('signed', $sentPost['provider_envelope_ref']);
        $postEnvelope = EsignEnvelope::query()->findOrFail($sentPost['id']);
        $this->assertTrue($postEnvelope->post_cancellation);

        $neutralUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unit->unit_class_id,
            'unit_number' => 'A-202',
        ]);
        [$neutralContract, $neutralDocument] = $this->prepareAwaitingOnUnit(
            $neutralUnit,
            $this->contactWithEmail('neutral@example.com')
        );
        $this->sendEnvelope($neutralContract, $neutralDocument->id);

        $list = $this->getJson('/api/contracts');
        $list->assertOk();
        $list->assertJsonPath('meta.declined_count', 1);
        $list->assertJsonPath('meta.post_cancellation_count', 1);

        $declined = $this->getJson('/api/contracts?attention=declined');
        $declined->assertOk();
        $declinedIds = collect($declined->json('data'))->pluck('id')->all();
        $this->assertSame([$declinedContract->id], $declinedIds);
        $declined->assertJsonPath('meta.declined_count', 1);
        $declined->assertJsonPath('meta.post_cancellation_count', 1);

        $postCancel = $this->getJson('/api/contracts?attention=post_cancellation');
        $postCancel->assertOk();
        $postCancelIds = collect($postCancel->json('data'))->pluck('id')->all();
        $this->assertSame([$postCancelContract->id], $postCancelIds);
        $postCancel->assertJsonPath('meta.declined_count', 1);
        $postCancel->assertJsonPath('meta.post_cancellation_count', 1);

        $search = $this->postJson('/api/contracts/search', [
            'attention' => 'declined',
        ]);
        $search->assertOk();
        $this->assertSame(
            [$declinedContract->id],
            collect($search->json('data'))->pluck('id')->all()
        );
        $search->assertJsonPath('meta.declined_count', 1);
        $search->assertJsonPath('meta.post_cancellation_count', 1);
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
