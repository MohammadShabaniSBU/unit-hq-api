<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\ContractDocumentStatus;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\Contact;
use App\Models\ContractDocument;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\TemplateVersion;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
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

        // Editing opens a draft. The published variant, and the stored PDF, stay put.
        $variant = $this->variant('en');
        $blocks = $variant->blocks;
        $blocks['blocks'][0]['params']['heading'] = 'MUTATED HEADING';
        $this->putJson("/api/template-families/{$this->documentFamily->id}/variants/{$variant->id}", [
            'blocks' => $blocks,
        ])->assertOk();
        $this->assertNotSame('MUTATED HEADING', $variant->fresh()->blocks['blocks'][0]['params']['heading']);

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

    public function test_generate_pins_the_variants_version(): void
    {
        $contact = Contact::factory()->fiscalComplete()->create(['locale' => 'en']);
        $contract = $this->createRemoteContract($contact);

        $create = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'locale' => 'en',
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.template_version.version_number', 1);

        $document = ContractDocument::query()->findOrFail((int) $create->json('data.id'));
        $variant = TemplateVariant::query()->findOrFail($document->template_variant_id);
        $this->assertSame((int) $variant->template_version_id, (int) $document->template_version_id);
        $this->assertSame((int) $variant->template_version_id, (int) $create->json('data.template_version.id'));
    }

    public function test_unpublished_family_cannot_generate(): void
    {
        $contact = Contact::factory()->fiscalComplete()->create(['locale' => 'en']);
        $contract = $this->createRemoteContract($contact);

        $family = TemplateFamily::factory()->create([
            'channel' => TemplateChannel::Document,
            'purpose' => TemplatePurpose::Contract,
            'name' => 'Draft only rental agreement',
        ]);
        TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);

        $response = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'template_family_id' => $family->id,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('template_family_id');
        $response->assertJsonPath(
            'errors.template_family_id.0',
            __('errors.documents.template_not_published'),
        );
    }

    public function test_explicit_variant_must_be_a_published_version_of_the_family(): void
    {
        $contact = Contact::factory()->fiscalComplete()->create(['locale' => 'en']);
        $contract = $this->createRemoteContract($contact);

        $other = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Other family',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Other',
            'legacy_html' => '<p>other</p>',
        ]]);
        $otherVariantId = (int) $other->currentVersion()->firstOrFail()->variants()->value('id');

        $crossFamily = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'template_variant_id' => $otherVariantId,
        ]);
        $crossFamily->assertStatus(422);
        $crossFamily->assertJsonValidationErrors('template_variant_id');

        $draft = $this->postJson("/api/template-families/{$this->documentFamily->id}/versions");
        $draft->assertCreated();
        $draftVariantId = (int) $draft->json('data.variants.0.id');

        $draftVariant = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'template_variant_id' => $draftVariantId,
        ]);
        $draftVariant->assertStatus(422);
        $draftVariant->assertJsonPath(
            'errors.template_variant_id.0',
            __('errors.templates.variant_mismatch'),
        );
    }

    public function test_explicit_variant_from_a_superseded_published_version_is_refused(): void
    {
        $contact = Contact::factory()->fiscalComplete()->create(['locale' => 'en']);
        $contract = $this->createRemoteContract($contact);

        $create = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'locale' => 'en',
        ]);
        $create->assertCreated();
        $v1VariantId = (int) $create->json('data.template_variant_id');

        $this->publishNextDocumentVersion('CLAUSE FIX');

        $stale = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'template_variant_id' => $v1VariantId,
        ]);
        $stale->assertStatus(422);
        $stale->assertJsonPath(
            'errors.template_variant_id.0',
            __('errors.templates.variant_not_current'),
        );
    }

    public function test_regenerate_uses_latest_published_version(): void
    {
        $contact = Contact::factory()->fiscalComplete()->create(['locale' => 'en']);
        $contract = $this->createRemoteContract($contact);

        $create = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'locale' => 'en',
        ]);
        $create->assertCreated();
        $documentId = (int) $create->json('data.id');
        $v1Id = (int) $create->json('data.template_version.id');

        $versionId = $this->publishNextDocumentVersion('CLAUSE FIX');

        $regen = $this->postJson("/api/contracts/{$contract->id}/documents/{$documentId}/regenerate");
        $regen->assertOk();
        $regen->assertJsonPath('data.template_version.version_number', 2);
        $regen->assertJsonPath('data.locale', 'en');

        $superseded = ContractDocument::query()->findOrFail($documentId);
        $this->assertSame(ContractDocumentStatus::Superseded, $superseded->status);
        $this->assertSame($v1Id, (int) $superseded->template_version_id);
        $this->assertSame($versionId, (int) $regen->json('data.template_version.id'));
        $this->assertNotSame($v1Id, $versionId);
    }

    public function test_regenerate_falls_back_to_ladder_when_locale_is_gone(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $contact = Contact::factory()->fiscalComplete()->create(['locale' => 'en']);
        $contract = $this->createRemoteContract($contact);

        $create = $this->postJson("/api/contracts/{$contract->id}/documents", [
            'locale' => 'en',
        ]);
        $create->assertCreated();
        $documentId = (int) $create->json('data.id');

        $draft = $this->postJson("/api/template-families/{$this->documentFamily->id}/versions");
        $draft->assertCreated();
        $versionId = (int) $draft->json('data.id');
        $enVariantId = (int) collect($draft->json('data.variants'))->firstWhere('locale', 'en')['id'];

        $this->deleteJson("/api/template-families/{$this->documentFamily->id}/variants/{$enVariantId}")
            ->assertNoContent();
        $this->postJson("/api/template-families/{$this->documentFamily->id}/versions/{$versionId}/publish")
            ->assertOk();

        $regen = $this->postJson("/api/contracts/{$contract->id}/documents/{$documentId}/regenerate");
        $regen->assertOk();
        $regen->assertJsonPath('data.locale', 'es');
        $regen->assertJsonPath('data.template_version.version_number', 2);

        $superseded = ContractDocument::query()->with('templateVariant')->findOrFail($documentId);
        $this->assertSame('en', $superseded->templateVariant->locale);
        $this->assertSame(1, (int) $superseded->templateVersion()->value('version_number'));

        $match = collect($logged)->first(fn (MessageLogged $event): bool => $event->level === 'info'
            && ($event->context['missing_locale'] ?? null) === 'en'
            && ($event->context['contract_id'] ?? null) === $contract->id
            && ($event->context['template_version_id'] ?? null) === $versionId);
        $this->assertNotNull($match);
    }

    private function publishNextDocumentVersion(string $heading): int
    {
        $draft = $this->postJson("/api/template-families/{$this->documentFamily->id}/versions");
        $draft->assertCreated();
        $versionId = (int) $draft->json('data.id');
        $en = collect($draft->json('data.variants'))->firstWhere('locale', 'en');
        $blocks = $en['blocks'];
        $blocks['blocks'][0]['params']['heading'] = $heading;

        $this->putJson("/api/template-families/{$this->documentFamily->id}/variants/{$en['id']}", [
            'blocks' => $blocks,
        ])->assertOk();
        $this->postJson("/api/template-families/{$this->documentFamily->id}/versions/{$versionId}/publish")
            ->assertOk();

        return $versionId;
    }
}
