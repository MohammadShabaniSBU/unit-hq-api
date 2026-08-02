<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Documents\CreatesContractDocumentFixtures;
use Tests\TestCase;

class DocLocaleTest extends TestCase
{
    use CreatesContractDocumentFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedDocumentWorld();
    }

    public function test_ladder_and_override_logged(): void
    {
        // Contact locale es → ladder picks es.
        $esContact = Contact::factory()->fiscalComplete()->create(['locale' => 'es']);
        $contract = $this->createRemoteContract($esContact);

        $resolved = $this->postJson("/api/contracts/{$contract->id}/documents");
        $resolved->assertCreated();
        $this->assertSame('es', $resolved->json('data.locale'));

        // Conscious override to en is logged.
        $override = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'locale' => 'en',
        ]);
        $override->assertCreated();
        $this->assertSame('en', $override->json('data.locale'));

        $activity = Activity::query()
            ->where('event', 'contract.document.locale_overridden')
            ->where('subject_type', $contract->getMorphClass())
            ->where('subject_id', $contract->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('es', $activity->properties['resolved_locale'] ?? null);
        $this->assertSame('en', $activity->properties['chosen_locale'] ?? null);

        // Site ladder: null contact locale + ES site → es.
        $siteContact = Contact::factory()->fiscalComplete()->create(['locale' => null]);
        $unit = \App\Models\Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unit->unit_class_id,
            'unit_number' => 'C-303',
        ]);
        $siteContractResponse = $this->postJson('/api/contracts', [
            'contact_id' => $siteContact->id,
            'start_date' => '2026-08-01',
            'move_in_date' => '2026-08-01',
            'deposit_amount' => '0.00',
            'signature_mode' => 'remote',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '80.00',
            ]],
        ]);
        $siteContractResponse->assertCreated();
        $siteContractId = (int) $siteContractResponse->json('data.id');

        $siteDoc = $this->postJson("/api/contracts/{$siteContractId}/documents");
        $siteDoc->assertCreated();
        $this->assertSame('es', $siteDoc->json('data.locale'));
    }
}
