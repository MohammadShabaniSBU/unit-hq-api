<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\ContractDocumentStatus;
use App\Models\Contact;
use App\Models\ContractDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Documents\CreatesContractDocumentFixtures;
use Tests\TestCase;

class DocSnapshotTest extends TestCase
{
    use CreatesContractDocumentFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedDocumentWorld();
    }

    public function test_freeze_supersede_guard(): void
    {
        $contact = Contact::factory()->fiscalComplete()->create(['locale' => 'en']);
        $contract = $this->createRemoteContract($contact);

        $create = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'locale' => 'en',
        ]);
        $create->assertCreated();
        $documentId = (int) $create->json('data.id');
        $sha = (string) $create->json('data.sha256');
        $path = ContractDocument::query()->findOrFail($documentId)->pdf_path;
        $bytes = Storage::disk('local')->get($path);

        // Template edit after generation must leave stored draft unchanged.
        $variant = $this->variant('en');
        $blocks = $variant->blocks;
        $blocks['blocks'][0]['params']['heading'] = 'MUTATED HEADING';
        $variant->update(['blocks' => $blocks]);

        $this->assertSame($bytes, Storage::disk('local')->get($path));
        $this->assertSame($sha, ContractDocument::query()->findOrFail($documentId)->sha256);

        $regen = $this->postJson("/api/contracts/{$contract->id}/documents/{$documentId}/regenerate");
        $regen->assertOk();
        $newId = (int) $regen->json('data.id');
        $this->assertNotSame($documentId, $newId);
        $this->assertSame(
            ContractDocumentStatus::Superseded,
            ContractDocument::query()->findOrFail($documentId)->status,
        );
        $this->assertSame(
            ContractDocumentStatus::Draft,
            ContractDocument::query()->findOrFail($newId)->status,
        );

        // Sent docs refuse regeneration.
        ContractDocument::query()->whereKey($newId)->update([
            'status' => ContractDocumentStatus::Sent,
        ]);
        $frozen = $this->postJson("/api/contracts/{$contract->id}/documents/{$newId}/regenerate");
        $frozen->assertStatus(422);
    }
}
