<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\Employee;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\TemplateVersion;
use App\Support\Communications\Exceptions\PublishedTemplateImmutable;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TemplateVersionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_put_on_published_family_opens_next_draft_and_leaves_current(): void
    {
        $this->actingManager();
        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'Pay',
            'legacy_html' => '<p>pay</p>',
        ]);
        $current = $family->currentVersion()->firstOrFail();
        $variant = $current->variants()->firstOrFail();

        $update = $this->putJson("/api/template-families/{$family->id}/variants/{$variant->id}", [
            'subject' => 'Pay now',
        ]);

        $update->assertOk();
        $this->assertSame('Pay', $update->json('data.current_version.variants.0.subject'));
        $this->assertSame('<p>pay</p>', $variant->fresh()->legacy_html);
        $this->assertSame(2, $update->json('data.draft_version.version_number'));
        $this->assertSame('Pay now', $update->json('data.draft_version.variants.0.subject'));
        $this->assertNotSame($variant->id, $update->json('data.draft_version.variants.0.id'));
        $this->assertSame($current->id, $update->json('data.draft_version.based_on_version_id'));
        $this->assertVersionActivity('template.version.drafted', 2, $current->id);
    }

    public function test_second_create_draft_returns_existing_draft(): void
    {
        $this->actingManager();
        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'Hi',
            'legacy_html' => '<p>Hi</p>',
        ]);

        $first = $this->postJson("/api/template-families/{$family->id}/versions");
        $first->assertCreated();
        $draftId = (int) $first->json('data.id');

        $second = $this->postJson("/api/template-families/{$family->id}/versions");
        $second->assertStatus(409);
        $second->assertJsonPath('data.id', $draftId);
        $this->assertSame(1, $family->versions()->where('status', TemplateVersionStatus::Draft)->count());
    }

    public function test_postgres_rejects_a_second_draft_row(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('tver_one_draft_idx is postgres-only.');
        }

        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'Hi',
            'legacy_html' => '<p>Hi</p>',
        ]);

        TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 2,
            'status' => TemplateVersionStatus::Draft,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 3,
            'status' => TemplateVersionStatus::Draft,
        ]);
    }

    public function test_publish_broken_document_names_variant_and_locale(): void
    {
        $this->actingManager();

        $create = $this->postJson('/api/template-families', [
            'channel' => 'document',
            'name' => 'Broken lease',
            'purpose' => 'contract',
            'locale' => 'es',
            'blocks' => [
                'version' => 1,
                'blocks' => [[
                    'id' => 'p1',
                    'type' => 'paragraph',
                    'params' => ['html' => '<p>Hi</p>'],
                ]],
            ],
        ]);
        $create->assertCreated();

        $familyId = (int) $create->json('data.id');
        $versionId = (int) $create->json('data.draft_version.id');
        $variantId = (int) $create->json('data.draft_version.variants.0.id');

        $publish = $this->postJson("/api/template-families/{$familyId}/versions/{$versionId}/publish");
        $publish->assertStatus(422);
        $body = $publish->getContent();
        $this->assertStringContainsString('es', $body);
        $this->assertStringContainsString((string) $variantId, $body);
        $this->assertSame(TemplateVersionStatus::Draft, TemplateVersion::query()->findOrFail($versionId)->status);
    }

    public function test_publish_returns_token_warnings_and_freezes_the_draft(): void
    {
        $this->actingManager();
        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'Hi {{contact.first_name}} {{contact.not_a_token}}',
            'legacy_html' => '<p>Hello</p>',
        ]);
        $sourceId = (int) $family->currentVersion()->firstOrFail()->id;

        $draft = $this->postJson("/api/template-families/{$family->id}/versions");
        $draft->assertCreated();
        $versionId = (int) $draft->json('data.id');

        $publish = $this->postJson("/api/template-families/{$family->id}/versions/{$versionId}/publish");
        $publish->assertOk();
        $publish->assertJsonPath('data.draft_version', null);
        $publish->assertJsonPath('data.current_version.version_number', 2);
        $tokens = $publish->json('warnings.0.tokens');
        $this->assertContains('contact.not_a_token', $tokens);
        $this->assertNotContains('contact.first_name', $tokens);
        $this->assertVersionActivity('template.version.published', 2, $sourceId);
    }

    public function test_eloquent_cannot_change_a_published_variant(): void
    {
        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'Pay',
            'legacy_html' => '<p>pay</p>',
        ]);
        $variant = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();

        try {
            $variant->update(['subject' => 'nope']);
            $this->fail('Expected PublishedTemplateImmutable on update.');
        } catch (PublishedTemplateImmutable $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertSame('Pay', $variant->fresh()->subject);

        $version = $family->currentVersion()->firstOrFail();
        $this->expectException(PublishedTemplateImmutable::class);
        $version->delete();
    }

    public function test_postgres_trigger_rejects_raw_update_of_published_variant(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('published immutability trigger is postgres-only.');
        }

        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'Pay',
            'legacy_html' => '<p>pay</p>',
        ]);
        $variant = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();

        try {
            DB::transaction(function () use ($variant): void {
                DB::table('template_variants')->where('id', $variant->id)->update(['subject' => 'hacked']);
            });
            $this->fail('Expected published_template_immutable.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('published_template_immutable', $exception->getMessage());
        }

        $this->assertSame('Pay', $variant->fresh()->subject);

        $version = $family->currentVersion()->firstOrFail();
        try {
            DB::transaction(function () use ($version): void {
                DB::table('template_versions')->where('id', $version->id)->update(['version_number' => 99]);
            });
            $this->fail('Expected published_template_immutable on the version row.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('published_template_immutable', $exception->getMessage());
        }
    }

    public function test_restore_copies_an_older_version_into_the_next_draft(): void
    {
        $this->actingManager();
        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'v1',
            'legacy_html' => '<p>1</p>',
        ]);

        $v2 = null;
        for ($number = 2; $number <= 5; $number++) {
            $version = TemplateVersion::query()->create([
                'template_family_id' => $family->id,
                'version_number' => $number,
                'status' => TemplateVersionStatus::Published,
                'published_at' => now(),
            ]);
            $version->variants()->create([
                'template_family_id' => $family->id,
                'locale' => 'en',
                'subject' => 'v'.$number,
                'legacy_html' => '<p>'.$number.'</p>',
            ]);
            if ($number === 2) {
                $v2 = $version;
            }
        }

        $this->assertInstanceOf(TemplateVersion::class, $v2);

        $restore = $this->postJson("/api/template-families/{$family->id}/versions", [
            'from_version_id' => $v2->id,
        ]);
        $restore->assertCreated();
        $restore->assertJsonPath('data.version_number', 6);
        $restore->assertJsonPath('data.based_on_version_id', $v2->id);
        $restore->assertJsonPath('data.variants.0.subject', 'v2');
        $restore->assertJsonPath('data.variants.0.legacy_html', '<p>2</p>');
        $this->assertSame('v5', $family->currentVersion()->firstOrFail()->variants()->firstOrFail()->subject);
        $this->assertVersionActivity('template.version.restored', 6, $v2->id);
    }

    public function test_discard_removes_only_the_draft(): void
    {
        $this->actingManager();
        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'Hi',
            'legacy_html' => '<p>Hi</p>',
        ]);
        $publishedId = (int) $family->currentVersion()->firstOrFail()->id;

        $draft = $this->postJson("/api/template-families/{$family->id}/versions");
        $draft->assertCreated();
        $draftId = (int) $draft->json('data.id');

        $this->deleteJson("/api/template-families/{$family->id}/versions/{$draftId}")
            ->assertNoContent();
        $this->assertNull(TemplateVersion::query()->find($draftId));
        $this->assertSame(0, TemplateVariant::query()->where('template_version_id', $draftId)->count());
        $this->assertVersionActivity('template.version.discarded', 2, $publishedId);

        $this->deleteJson("/api/template-families/{$family->id}/versions/{$publishedId}")
            ->assertStatus(422);
        $this->assertNotNull(TemplateVersion::query()->find($publishedId));
    }

    public function test_delete_published_variant_is_refused(): void
    {
        $this->actingManager();
        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'Pay',
            'legacy_html' => '<p>pay</p>',
        ]);
        $variant = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();

        $this->deleteJson("/api/template-families/{$family->id}/variants/{$variant->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.variant.0', __('errors.templates.version_published'));

        $this->assertNotNull(TemplateVariant::query()->find($variant->id));
        $this->assertSame('Pay', $variant->fresh()->subject);
    }

    public function test_put_published_variant_is_refused_while_a_draft_exists(): void
    {
        $this->actingManager();
        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'Pay',
            'legacy_html' => '<p>pay</p>',
        ]);
        $variant = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();

        $this->postJson("/api/template-families/{$family->id}/versions")->assertCreated();

        $this->putJson("/api/template-families/{$family->id}/variants/{$variant->id}", [
            'subject' => 'Changed',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.variant.0', __('errors.templates.version_published'));

        $this->assertSame('Pay', $variant->fresh()->subject);
    }

    public function test_put_on_an_older_published_version_is_refused(): void
    {
        $this->actingManager();
        $family = $this->publishedEmail([
            'locale' => 'en',
            'subject' => 'v1',
            'legacy_html' => '<p>1</p>',
        ]);
        $older = $family->currentVersion()->firstOrFail()->variants()->firstOrFail();

        $current = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 2,
            'status' => TemplateVersionStatus::Published,
            'published_at' => now(),
        ]);
        $current->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'v2',
            'legacy_html' => '<p>2</p>',
        ]);

        $this->putJson("/api/template-families/{$family->id}/variants/{$older->id}", [
            'subject' => 'rewritten',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.variant.0', __('errors.templates.version_published'));

        $this->assertSame('v1', $older->fresh()->subject);
        $this->assertSame('v2', $family->currentVersion()->firstOrFail()->variants()->firstOrFail()->subject);
    }

    private function actingManager(): Employee
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private function publishedEmail(array $variant): TemplateFamily
    {
        return TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Lifecycle',
            'purpose' => TemplatePurpose::General,
        ], variants: [$variant]);
    }

    private function assertVersionActivity(string $event, int $versionNumber, ?int $basedOn): void
    {
        $row = Activity::query()->where('description', $event)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('comms', $row->log_name);
        $this->assertSame($versionNumber, (int) $row->properties['version_number']);
        $stored = $row->properties['based_on_version_id'] ?? null;
        $this->assertSame($basedOn, $stored === null ? null : (int) $stored);
    }
}
