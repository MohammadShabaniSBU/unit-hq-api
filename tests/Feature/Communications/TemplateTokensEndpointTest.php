<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplatePurpose;
use App\Models\Employee;
use App\Models\TemplateFamily;
use App\Support\Templates\TokenCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class TemplateTokensEndpointTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function returns_purpose_availability(): void
    {
        Sanctum::actingAs(Employee::factory()->manager()->create());

        $debt = TemplateFamily::factory()->create([
            'purpose' => TemplatePurpose::Debt,
        ]);
        $debtResponse = $this->getJson("/api/template-families/{$debt->id}/tokens");
        $debtResponse->assertOk();
        $debtRows = collect($debtResponse->json('data'));

        $this->assertCount(count(TokenCatalog::all()), $debtRows);
        $row = $debtRows->first();
        $this->assertIsArray($row);
        $this->assertSame(
            ['path', 'group', 'type', 'label', 'description', 'sample', 'available'],
            array_keys($row),
        );

        $debtByPath = $debtRows->keyBy('path');
        $this->assertTrue($debtByPath['contract.balance_owed']['available']);
        $this->assertTrue($debtByPath['pay_link']['available']);
        $this->assertFalse($debtByPath['unsubscribe_url']['available']);
        $this->assertSame('Balance owed', $debtByPath['contract.balance_owed']['label']);
        $this->assertSame('0.00', $debtByPath['contract.balance_owed']['sample']);

        $newsletter = TemplateFamily::factory()->create([
            'purpose' => TemplatePurpose::Newsletter,
        ]);
        $newsletterRows = collect(
            $this->getJson("/api/template-families/{$newsletter->id}/tokens")->assertOk()->json('data'),
        )->keyBy('path');
        $this->assertFalse($newsletterRows['contract.balance_owed']['available']);
        $this->assertFalse($newsletterRows['pay_link']['available']);
        $this->assertTrue($newsletterRows['unsubscribe_url']['available']);
        $this->assertTrue($newsletterRows['newsletter.issue_number']['available']);
    }

    #[Test]
    public function translates_labels_for_the_request_locale(): void
    {
        Sanctum::actingAs(Employee::factory()->manager()->create());
        $family = TemplateFamily::factory()->create([
            'purpose' => TemplatePurpose::Debt,
        ]);

        $response = $this->withHeader('Accept-Language', 'es')
            ->getJson("/api/template-families/{$family->id}/tokens");
        $response->assertOk();

        $label = collect($response->json('data'))
            ->firstWhere('path', 'contract.balance_owed')['label'] ?? null;
        $this->assertSame('Saldo adeudado', $label);
    }

    #[Test]
    public function forbids_employees_without_template_manage(): void
    {
        Sanctum::actingAs($this->employeeWithoutPermissions());
        $family = TemplateFamily::factory()->create();

        $this->getJson("/api/template-families/{$family->id}/tokens")->assertForbidden();
    }
}
