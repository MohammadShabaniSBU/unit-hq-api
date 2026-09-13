<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

class UnitCsvTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();

        $this->siteA->update(['code' => 'MAD-01']);
        $this->siteB->update(['code' => 'MAD-02']);
        $this->unitA->update(['unit_number' => 'MAD-01-AL3-07']);
        $this->unitB->update(['unit_number' => 'MAD-02-AL3-01']);
    }

    #[Test]
    public function export_is_not_resolved_as_a_unit_id(): void
    {
        Sanctum::actingAs($this->owner);

        $this->get('/api/units/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    #[Test]
    public function export_uses_codes_not_ids_and_only_visible_units(): void
    {
        Sanctum::actingAs($this->owner);

        $ownerCsv = $this->get('/api/units/export')->assertOk();
        $ownerCsv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $ownerCsv->assertHeader('Content-Disposition', 'attachment; filename="units.csv"');

        $ownerBody = $ownerCsv->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $ownerBody);
        $this->assertStringContainsString('site_code,unit_class_code,unit_number', $ownerBody);
        $this->assertStringContainsString($this->csvUnitRow('MAD-01', $this->unitClass->code, 'MAD-01-AL3-07', enabled: 'true'), $ownerBody);
        $this->assertStringContainsString($this->csvUnitRow('MAD-02', $this->unitClass->code, 'MAD-02-AL3-01', enabled: 'true'), $ownerBody);
        $this->assertDoesNotMatchRegularExpression('/^\d+,\d+,/m', $this->stripBom($ownerBody));

        Sanctum::actingAs($this->agent);

        $agentBody = $this->get('/api/units/export')->assertOk()->getContent();
        $this->assertStringContainsString($this->csvUnitRow('MAD-01', $this->unitClass->code, 'MAD-01-AL3-07', enabled: 'true'), $agentBody);
        $this->assertStringNotContainsString($this->csvUnitRow('MAD-02', $this->unitClass->code, 'MAD-02-AL3-01', enabled: 'true'), $agentBody);
    }

    #[Test]
    public function import_creates_a_new_unit(): void
    {
        Sanctum::actingAs($this->owner);

        $this->post('/api/units/import', [
            'file' => $this->csvFile($this->csvBody([
                $this->csvUnitRow('MAD-01', $this->unitClass->code, 'MAD-01-AL3-99', '2.50', '3.00', '2.60', 'Surveyed', 'true'),
            ])),
        ])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 0);

        $this->assertDatabaseHas('units', [
            'site_id' => $this->siteA->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => 'MAD-01-AL3-99',
            'actual_width' => '2.50',
            'actual_depth' => '3.00',
            'actual_height' => '2.60',
            'note' => 'Surveyed',
            'enabled' => true,
        ]);
    }

    #[Test]
    public function import_updates_an_existing_unit(): void
    {
        $otherClass = UnitClass::factory()->create(['code' => 'XL-IMPORT']);

        Sanctum::actingAs($this->owner);

        $this->post('/api/units/import', [
            'file' => $this->csvFile($this->csvBody([
                $this->csvUnitRow('MAD-01', 'XL-IMPORT', 'MAD-01-AL3-07', '2.54', '3.37', '2.60', 'Updated note', 'false'),
            ])),
        ])->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.updated', 1);

        $this->unitA->refresh();
        $this->assertSame($otherClass->id, $this->unitA->unit_class_id);
        $this->assertSame('2.54', $this->unitA->actual_width);
        $this->assertSame('3.37', $this->unitA->actual_depth);
        $this->assertSame('2.60', $this->unitA->actual_height);
        $this->assertSame('Updated note', $this->unitA->note);
        $this->assertFalse($this->unitA->enabled);
    }

    #[Test]
    public function unknown_site_or_class_returns_422_and_writes_nothing(): void
    {
        Sanctum::actingAs($this->owner);
        $before = Unit::query()->count();

        $response = $this->post('/api/units/import', [
            'file' => $this->csvFile($this->csvBody([
                $this->csvUnitRow('MAD-99', $this->unitClass->code, 'NEW-01'),
                $this->csvUnitRow('MAD-01', 'NOPE', 'NEW-02'),
            ])),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.row', 2);
        $response->assertJsonPath('data.errors.1.row', 3);
        $this->assertSame($before, Unit::query()->count());
    }

    #[Test]
    public function site_scoped_employee_cannot_import_into_an_ungranted_site(): void
    {
        $manager = Employee::factory()->withoutRoleGrant()->create();
        $this->grantRole($manager, 'site_manager', $this->siteA);
        Sanctum::actingAs($manager);

        $before = Unit::query()->where('site_id', $this->siteB->id)->count();

        $response = $this->post('/api/units/import', [
            'file' => $this->csvFile($this->csvBody([
                $this->csvUnitRow('MAD-02', $this->unitClass->code, 'MAD-02-NEW-01'),
            ])),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.errors.0.row', 2);
        $this->assertSame($before, Unit::query()->where('site_id', $this->siteB->id)->count());
    }

    /**
     * @param  list<string>  $rows
     */
    private function csvBody(array $rows): string
    {
        return "site_code,unit_class_code,unit_number,actual_width,actual_depth,actual_height,note,enabled\n"
            .implode("\n", $rows)."\n";
    }

    private function csvUnitRow(
        string $siteCode,
        string $classCode,
        string $unitNumber,
        string $width = '',
        string $depth = '',
        string $height = '',
        string $note = '',
        string $enabled = '',
    ): string {
        return implode(',', [$siteCode, $classCode, $unitNumber, $width, $depth, $height, $note, $enabled]);
    }

    private function csvFile(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('units.csv', $contents);
    }

    private function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF")
            ? substr($contents, 3)
            : $contents;
    }
}
