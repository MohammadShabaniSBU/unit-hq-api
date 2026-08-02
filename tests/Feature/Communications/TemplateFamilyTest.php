<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Employee;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
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

        TemplateFamily::query()->create([
            'channel' => TemplateChannel::Email,
            'name' => 'Debt note',
            'purpose' => TemplatePurpose::Debt,
        ])->variants()->create([
            'locale' => 'en',
            'subject' => 'Pay',
            'legacy_html' => '<p>pay</p>',
        ]);

        TemplateFamily::query()->create([
            'channel' => TemplateChannel::Email,
            'name' => 'Lead chase',
            'purpose' => TemplatePurpose::Lead,
        ])->variants()->create([
            'locale' => 'en',
            'subject' => 'Hello',
            'legacy_html' => '<p>lead</p>',
        ]);

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
}
