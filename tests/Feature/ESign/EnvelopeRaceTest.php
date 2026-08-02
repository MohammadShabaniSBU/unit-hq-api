<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Enums\ContractStatus;
use App\Enums\EsignEnvelopeStatus;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\EsignEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ESign\CreatesEnvelopeFixtures;
use Tests\TestCase;

class EnvelopeRaceTest extends TestCase
{
    use CreatesEnvelopeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEnvelopeWorld();
    }

    public function test_both_orders(): void
    {
        // --- cancel then signed: loud record, no completion ---
        [$contractA, $documentA] = $this->prepareAwaitingWithDocument();
        $sentA = $this->sendEnvelope($contractA, $documentA->id);
        $envelopeA = EsignEnvelope::query()->findOrFail($sentA['id']);

        $this->postJson("/api/contracts/{$contractA->id}/cancel")->assertOk();
        $contractA->refresh();
        $this->assertSame(ContractStatus::Cancelled, $contractA->status);

        $envelopeA->refresh();
        $this->assertSame(EsignEnvelopeStatus::Cancelled, $envelopeA->status);

        $this->fireWebhook('signed', $envelopeA->provider_envelope_ref);
        $envelopeA->refresh();
        $contractA->refresh();

        $this->assertSame(EsignEnvelopeStatus::Signed, $envelopeA->status);
        $this->assertTrue($envelopeA->post_cancellation);
        $this->assertNotNull($envelopeA->signed_pdf_path);
        $this->assertSame(ContractStatus::Cancelled, $contractA->status);
        $this->assertSame(0, Charge::query()->where('contract_id', $contractA->id)->count());
        $this->assertNotNull(
            Activity::query()->where('description', 'esign.signed_after_cancellation')->first()
        );

        // --- signed then cancel: envelope cancel refuses; awaiting claim loses ---
        [$contractB, $documentB] = $this->prepareAwaitingWithDocument(
            $this->contactWithEmail('second@example.com')
        );
        $sentB = $this->sendEnvelope($contractB, $documentB->id);
        $envelopeB = EsignEnvelope::query()->findOrFail($sentB['id']);

        $this->fireWebhook('signed', $envelopeB->provider_envelope_ref);
        $envelopeB->refresh();
        $contractB->refresh();

        $this->assertSame(EsignEnvelopeStatus::Signed, $envelopeB->status);
        $this->assertFalse($envelopeB->post_cancellation);
        $this->assertContains($contractB->status, [
            ContractStatus::Pending,
            ContractStatus::Active,
        ]);

        // Operator envelope cancel after signed refuses.
        $this->postJson("/api/contracts/{$contractB->id}/envelopes/{$envelopeB->id}/cancel")
            ->assertStatus(422);

        // Claim-order: a racing awaiting→cancelled update loses (0 rows).
        $claimed = Contract::query()
            ->whereKey($contractB->id)
            ->where('status', ContractStatus::AwaitingSignature->value)
            ->update(['status' => ContractStatus::Cancelled->value]);
        $this->assertSame(0, $claimed);

        $contractB->refresh();
        $this->assertContains($contractB->status, [
            ContractStatus::Pending,
            ContractStatus::Active,
        ]);
    }
}
