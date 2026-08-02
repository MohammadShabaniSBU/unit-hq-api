<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Models\EmailBlock;
use App\Models\EmailTemplate;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TemplateMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string|null> */
    protected array $connectionsToTransact = [];

    public function test_report_confirm_reference_sweep(): void
    {
        $this->assertTrue(Schema::hasTable('email_templates'));

        $template = EmailTemplate::query()->create(['name' => 'Payment reminder']);
        EmailBlock::query()->create([
            'email_template_id' => $template->id,
            'type' => 'text',
            'props' => [
                'content' => 'Hello {{contact.first_name}}',
                'align' => 'left',
                'fontSize' => 16,
                'color' => '#000000',
            ],
            'order' => 0,
        ]);

        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::DebtProcess,
            'name' => 'Migrate me',
            'is_active' => false,
            'enrolment_filters' => [],
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::SendEmail,
            'params' => ['email_template_id' => $template->id, 'label' => 'Remind'],
            'sort' => 0,
        ]);

        $report = Artisan::call('templates:migrate-families');
        $this->assertSame(0, $report);
        $this->assertSame(0, TemplateFamily::query()->count());
        $this->assertTrue(Schema::hasTable('email_templates'));

        $confirm = Artisan::call('templates:migrate-families', ['--confirm' => true]);
        $this->assertSame(0, $confirm);

        $this->assertFalse(Schema::hasTable('email_templates'));
        $this->assertFalse(Schema::hasTable('email_blocks'));

        $family = TemplateFamily::query()->find($template->id);
        $this->assertNotNull($family);
        $this->assertSame('Payment reminder', $family->name);
        $this->assertSame(1, TemplateVariant::query()->where('template_family_id', $family->id)->count());
        $this->assertNotNull($family->variants()->first()?->legacy_html);

        $step = PlaybookStep::query()->where('playbook_id', $playbook->id)->firstOrFail();
        $this->assertArrayHasKey('template_family_id', $step->params);
        $this->assertSame($template->id, $step->params['template_family_id']);
        $this->assertArrayNotHasKey('email_template_id', $step->params);
    }
}
