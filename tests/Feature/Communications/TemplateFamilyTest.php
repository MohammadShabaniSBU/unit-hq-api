<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Employee;
use App\Models\TemplateVariant;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TemplateFamilyTest extends TestCase
{
    use RefreshDatabase;

    public function test_structure_guards(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $create = $this->postJson('/api/template-families', [
            'channel' => 'email',
            'name' => 'Welcome',
            'purpose' => 'general',
            'locale' => 'en',
            'subject' => 'Welcome',
            'legacy_html' => '<p>Hi</p>',
        ]);
        $create->assertCreated();
        $familyId = (int) $create->json('data.id');

        $dup = $this->postJson("/api/template-families/{$familyId}/variants", [
            'locale' => 'en',
            'subject' => 'Dup',
            'legacy_html' => '<p>x</p>',
        ]);
        $dup->assertStatus(422);

        $es = $this->postJson("/api/template-families/{$familyId}/variants", [
            'locale' => 'es',
            'subject' => 'Bienvenido',
            'legacy_html' => '<p>Hola</p>',
        ]);
        $es->assertCreated();

        $variants = TemplateVariant::query()->where('template_family_id', $familyId)->get();
        $this->assertCount(2, $variants);

        $enId = (int) $variants->firstWhere('locale', 'en')->id;
        $this->deleteJson("/api/template-families/{$familyId}/variants/{$enId}")->assertNoContent();

        $lastId = (int) TemplateVariant::query()->where('template_family_id', $familyId)->value('id');
        $this->deleteJson("/api/template-families/{$familyId}/variants/{$lastId}")
            ->assertStatus(422);

        TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Debt note',
            'purpose' => TemplatePurpose::Debt,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Pay',
            'legacy_html' => '<p>pay</p>',
        ]]);

        TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Lead chase',
            'purpose' => TemplatePurpose::Lead,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Hello',
            'legacy_html' => '<p>lead</p>',
        ]]);

        $debt = $this->getJson('/api/template-families?purpose=debt&channel=email');
        $debt->assertOk();
        $debtNames = collect($debt->json('data'))->pluck('name')->all();
        $this->assertContains('Debt note', $debtNames);
        $this->assertContains('Welcome', $debtNames);
        $this->assertNotContains('Lead chase', $debtNames);

        $lead = $this->getJson('/api/template-families?purpose=lead&channel=email');
        $lead->assertOk();
        $leadNames = collect($lead->json('data'))->pluck('name')->all();
        $this->assertContains('Lead chase', $leadNames);
        $this->assertContains('Welcome', $leadNames);
        $this->assertNotContains('Debt note', $leadNames);
    }

    public function test_sendable_filter_keeps_families_with_a_published_version(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $draftOnly = $this->postJson('/api/template-families', [
            'channel' => 'email',
            'name' => 'Still drafting',
            'purpose' => 'general',
            'locale' => 'en',
            'subject' => 'Draft',
            'legacy_html' => '<p>draft</p>',
        ]);
        $draftOnly->assertCreated();

        $published = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Live welcome',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Hello',
            'legacy_html' => '<p>hi</p>',
        ]]);

        $this->postJson("/api/template-families/{$published->id}/versions")->assertCreated();

        $sendable = $this->getJson('/api/template-families?sendable=1&channel=email');
        $sendable->assertOk();
        $names = collect($sendable->json('data'))->pluck('name')->all();
        $this->assertContains('Live welcome', $names);
        $this->assertNotContains('Still drafting', $names);
        $this->assertNotNull(collect($sendable->json('data'))->firstWhere('name', 'Live welcome')['current_version']);
    }
}
