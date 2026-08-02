<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\AutomationNode;
use App\Models\Country;
use App\Models\EmailTemplate;
use App\Models\PlaybookStep;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Support\Communications\LegacyEmailBlocksHtml;
use App\Support\Communications\SiteLocale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateTemplateFamiliesCommand extends Command
{
    protected $signature = 'templates:migrate-families {--confirm : Write families/variants and rewrite references}';

    protected $description = 'Migrate EmailTemplate rows into template_families + template_variants (report, then --confirm)';

    public function handle(): int
    {
        if (! Schema::hasTable('email_templates')) {
            $this->info('email_templates table is gone; nothing to migrate.');

            return self::SUCCESS;
        }

        $locale = $this->detectInstallLocale();
        $templates = EmailTemplate::query()->with('emailBlocks')->orderBy('id')->get();

        if ($templates->isEmpty()) {
            $this->info('No email_templates rows found.');
            if ($this->option('confirm')) {
                $this->dropLegacyTables();
                $this->info('Dropped empty legacy email template tables.');
            }

            return self::SUCCESS;
        }

        $this->table(
            ['email_template_id', 'name', 'locale', 'blocks', 'purpose'],
            $templates->map(fn (EmailTemplate $t) => [
                $t->id,
                $t->name,
                $locale,
                $t->emailBlocks->count(),
                $this->guessPurpose($t->name),
            ])->all(),
        );

        $this->info("Proposed locale for all rows: {$locale} (from site-country inspection).");

        if (! $this->option('confirm')) {
            $this->warn('Dry run only. Re-run with --confirm to write.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($templates, $locale): void {
            foreach ($templates as $template) {
                $purpose = $this->guessPurpose($template->name);
                $legacyHtml = LegacyEmailBlocksHtml::fromEmailTemplate($template);

                // Preserve email_templates.id so playbook JSON only needs a key rename.
                TemplateFamily::withoutEvents(function () use ($template, $purpose, $locale, $legacyHtml): void {
                    $family = TemplateFamily::query()->find($template->id);
                    if ($family === null) {
                        $family = new TemplateFamily;
                        $family->forceFill([
                            'id' => $template->id,
                            'channel' => TemplateChannel::Email,
                            'name' => mb_substr($template->name, 0, 128),
                            'purpose' => $purpose,
                            'archived_at' => null,
                        ]);
                        $family->save();
                    } else {
                        $family->forceFill([
                            'channel' => TemplateChannel::Email,
                            'name' => mb_substr($template->name, 0, 128),
                            'purpose' => $purpose,
                            'archived_at' => null,
                        ])->save();
                    }

                    TemplateVariant::query()->updateOrCreate(
                        [
                            'template_family_id' => $family->id,
                            'locale' => $locale,
                        ],
                        [
                            'subject' => mb_substr($template->name, 0, 500),
                            'legacy_html' => $legacyHtml,
                            'body_text' => null,
                            'blocks' => null,
                        ],
                    );
                });
            }

            $this->rewritePlaybookSteps();
            $this->rewriteAutomationNodes();
            $this->assertNoDanglingRefs();
        });

        // DDL outside the data transaction — DROP aborts nested RefreshDatabase txs on pgsql.
        $this->dropLegacyTables();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('template_families', 'id'), COALESCE((SELECT MAX(id) FROM template_families), 1))");
            DB::statement("SELECT setval(pg_get_serial_sequence('template_variants', 'id'), COALESCE((SELECT MAX(id) FROM template_variants), 1))");
        }

        $this->info('Migration confirmed: families written, references rewritten, legacy tables dropped.');

        return self::SUCCESS;
    }

    private function detectInstallLocale(): string
    {
        $sites = Site::query()->with('country')->get();
        $codes = $sites
            ->map(fn (Site $s) => strtoupper((string) ($s->country?->code ?? '')))
            ->filter()
            ->countBy();

        if ($codes->get('ES', 0) > 0 && $codes->get('ES', 0) >= $codes->get('FR', 0)) {
            return 'es';
        }

        if ($codes->get('FR', 0) > 0 && $codes->get('ES', 0) === 0) {
            return 'fr';
        }

        // Fall back: if any country row is ES in DB and no sites, still prefer es when ES exists as default country.
        if (Country::query()->where('code', 'ES')->exists() && $sites->isEmpty()) {
            return 'es';
        }

        return SiteLocale::for($sites->first());
    }

    private function guessPurpose(string $name): string
    {
        $lower = mb_strtolower($name);
        if (str_contains($lower, 'payment') || str_contains($lower, 'debt') || str_contains($lower, 'overdue') || str_contains($lower, 'reminder')) {
            return TemplatePurpose::Debt->value;
        }

        return TemplatePurpose::General->value;
    }

    private function rewritePlaybookSteps(): void
    {
        PlaybookStep::query()->orderBy('id')->each(function (PlaybookStep $step): void {
            $params = $step->params ?? [];
            if (! is_array($params) || ! array_key_exists('email_template_id', $params)) {
                return;
            }

            $params['template_family_id'] = $params['email_template_id'];
            unset($params['email_template_id']);
            $step->params = $params;
            $step->save();
        });
    }

    private function rewriteAutomationNodes(): void
    {
        AutomationNode::query()
            ->where('type', 'action.send_email')
            ->orderBy('id')
            ->each(function (AutomationNode $node): void {
                $config = $node->config ?? [];
                if (! is_array($config)) {
                    return;
                }

                $id = $config['templateId'] ?? $config['template_id'] ?? $config['email_template_id'] ?? $config['template_family_id'] ?? null;
                if ($id === null) {
                    return;
                }

                $config['templateId'] = (int) $id;
                $config['template_family_id'] = (int) $id;
                unset($config['email_template_id'], $config['template_id']);
                $node->config = $config;
                $node->save();
            });
    }

    private function assertNoDanglingRefs(): void
    {
        $danglingSteps = PlaybookStep::query()
            ->get()
            ->filter(function (PlaybookStep $step): bool {
                $params = $step->params ?? [];

                return is_array($params) && array_key_exists('email_template_id', $params);
            });

        if ($danglingSteps->isNotEmpty()) {
            throw new \RuntimeException('Dangling playbook email_template_id refs remain after rewrite.');
        }
    }

    private function dropLegacyTables(): void
    {
        Schema::dropIfExists('email_blocks');
        Schema::dropIfExists('email_templates');
    }
}
