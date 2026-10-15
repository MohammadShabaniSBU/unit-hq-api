<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\CampaignType;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\Campaign;
use App\Models\Setting;
use App\Models\TemplateAsset;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Settings\GeneralSettings;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\SendClass;
use App\Support\Marketing\CampaignSendGate;
use App\Support\Templates\Assist\AssistContext;
use App\Support\Templates\Assist\AssistContextException;
use App\Support\Templates\TokenCatalog;
use App\Support\Templates\TokenDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssistContextTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const GUARDED = [
        'contacts',
        'contact_channels',
        'contracts',
        'charges',
        'payments',
        'invoices',
        'messages',
        'message_threads',
    ];

    #[Test]
    public function tokens_by_purpose(): void
    {
        $debt = AssistContext::for($this->familyWithDraft(TemplatePurpose::Debt), 'en');
        $debtPaths = array_column($debt->tokens, 'path');

        $this->assertContains('contract.balance_owed', $debtPaths);
        $this->assertContains('pay_link', $debtPaths);
        $this->assertSame($this->catalogueTokens(TemplatePurpose::Debt), $debt->tokens);

        $newsletter = AssistContext::for($this->familyWithDraft(TemplatePurpose::Newsletter), 'en');
        $newsletterPaths = array_column($newsletter->tokens, 'path');

        $this->assertContains('unsubscribe_url', $newsletterPaths);
        $this->assertNotContains('pay_link', $newsletterPaths);
        $this->assertSame($this->catalogueTokens(TemplatePurpose::Newsletter), $newsletter->tokens);
    }

    #[Test]
    public function send_class_matches_sender_mapping(): void
    {
        $this->assertSame(SendClass::Marketing, SendClass::forTemplatePurpose(TemplatePurpose::Marketing));
        $this->assertSame(SendClass::Marketing, SendClass::forTemplatePurpose(TemplatePurpose::Newsletter));
        $this->assertSame(SendClass::Transactional, SendClass::forTemplatePurpose(TemplatePurpose::Debt));
        $this->assertSame(SendClass::Transactional, SendClass::forTemplatePurpose(TemplatePurpose::Announcement));

        foreach (TemplatePurpose::cases() as $purpose) {
            $context = AssistContext::for($this->familyWithDraft($purpose), 'en');
            $this->assertSame(SendClass::forTemplatePurpose($purpose)->value, $context->sendClass);
        }

        foreach (CampaignType::cases() as $type) {
            $campaign = new Campaign(['type' => $type]);
            $this->assertSame(
                SendClass::forTemplatePurpose($type->templatePurpose()),
                CampaignSendGate::sendClass($campaign),
            );
        }
    }

    #[Test]
    public function no_crm_tables_queried(): void
    {
        $families = [];
        foreach (TemplatePurpose::cases() as $purpose) {
            $families[] = $this->familyWithDraft($purpose);
        }

        $hits = [];
        $armed = true;
        DB::listen(function (object $query) use (&$hits, &$armed): void {
            if (! $armed || ! property_exists($query, 'sql')) {
                return;
            }
            $sql = strtolower((string) $query->sql);
            foreach ($this->guardedHits($sql) as $table) {
                $hits[] = $table.' '.$sql;
            }
        });

        foreach ($families as $family) {
            AssistContext::for($family, 'en');
        }

        $armed = false;
        $this->assertSame([], $hits);
    }

    #[Test]
    public function prompt_block_stable(): void
    {
        $asset = TemplateAsset::query()->create([
            'hash' => hash('sha256', 'logo'),
            'disk_path' => 'logo.png',
            'original_filename' => 'logo.png',
            'mime_type' => 'image/png',
            'size_bytes' => 4,
            'created_by' => null,
        ]);
        Setting::setGeneral(GeneralSettings::default()->with(emailLogoAssetId: $asset->id));

        $family = $this->familyWithDraft(TemplatePurpose::Debt);
        $first = AssistContext::for($family, 'en');
        $block = $first->toPromptBlock();

        App::setLocale('es');
        $second = AssistContext::for($family, 'en')->toPromptBlock();
        $this->assertSame($block, $second);
        $this->assertSame($block, $first->toPromptBlock());

        $brandLine = $this->field($block, 'brand');
        $brand = json_decode($brandLine, true);
        $this->assertIsArray($brand);
        $this->assertTrue($brand['has_logo']);
        $keys = array_keys($brand);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);
        $this->assertArrayNotHasKey('logo_asset_id', $brand);
        $this->assertStringNotContainsString($asset->publicUrl(), $block);
        $this->assertStringNotContainsString('/api/public/template-assets/', $block);
    }

    #[Test]
    public function block_catalogue_matches_validator(): void
    {
        $this->assertSame(
            EmailBlockDocument::TYPES,
            array_column(EmailBlockDocument::describeForAssistant(), 'type'),
        );
    }

    #[Test]
    public function unknown_selection_is_null(): void
    {
        $document = [
            'version' => 2,
            'sections' => [[
                'id' => 'sec_aaaaaaaa',
                'columns' => [
                    ['id' => 'col_aaaaaaaa', 'blocks' => []],
                    ['id' => 'col_bbbbbbbb', 'blocks' => [[
                        'id' => 'blk_cccccccc',
                        'type' => 'paragraph',
                        'params' => ['html' => 'Hi'],
                    ]]],
                ],
            ]],
        ];
        $family = $this->familyWithDraft(TemplatePurpose::General, blocks: $document);

        $missing = AssistContext::for($family, 'en', ['kind' => 'block', 'id' => 'blk_missing'], $document);
        $this->assertNull($missing->selection);

        $mismatch = AssistContext::for($family, 'en', ['kind' => 'section', 'id' => 'blk_cccccccc'], $document);
        $this->assertNull($mismatch->selection);

        $matched = AssistContext::for($family, 'en', ['kind' => 'block', 'id' => 'blk_cccccccc']);
        $this->assertSame([
            'kind' => 'block',
            'id' => 'blk_cccccccc',
            'path' => 'sections[0].columns[1].blocks[0]',
        ], $matched->selection);

        $sms = TemplateFamily::factory()->create([
            'channel' => TemplateChannel::Sms,
            'purpose' => TemplatePurpose::General,
        ]);
        try {
            AssistContext::for($sms, 'en');
            $this->fail('SMS context must throw.');
        } catch (AssistContextException $exception) {
            $this->assertSame('assist.channel_unsupported', $exception->errorKey);
        }

        try {
            AssistContext::for($family, 'fr');
            $this->fail('Missing locale must throw.');
        } catch (AssistContextException $exception) {
            $this->assertSame('assist.locale_missing', $exception->errorKey);
        }
    }

    /**
     * @param  array<string, mixed>|null  $blocks
     */
    private function familyWithDraft(TemplatePurpose $purpose, ?array $blocks = null): TemplateFamily
    {
        $family = TemplateFamily::factory()->create([
            'channel' => TemplateChannel::Email,
            'purpose' => $purpose,
            'name' => $purpose->value.' template',
        ]);
        $version = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $version->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'Subject',
            'blocks' => $blocks,
        ]);

        return $family;
    }

    /**
     * @return list<array{path: string, type: string, description: string, sample: string}>
     */
    private function catalogueTokens(TemplatePurpose $purpose): array
    {
        return array_map(
            fn (TokenDefinition $token): array => [
                'path' => $token->path,
                'type' => $token->type->value,
                'description' => __($token->descriptionKey, [], 'en'),
                'sample' => $token->sample,
            ],
            TokenCatalog::forPurpose($purpose),
        );
    }

    /**
     * @return list<string>
     */
    private function guardedHits(string $sql): array
    {
        $hits = [];
        foreach (self::GUARDED as $table) {
            $pattern = '/(^|[^a-z0-9_])'.preg_quote($table, '/').'([^a-z0-9_]|$)/';
            if (preg_match($pattern, $sql) === 1) {
                $hits[] = $table;
            }
        }
        if (preg_match('/(^|[^a-z0-9_])delinquency[a-z0-9_]*/', $sql) === 1) {
            $hits[] = 'delinquency*';
        }

        return $hits;
    }

    private function field(string $block, string $name): string
    {
        foreach (explode("\n", $block) as $line) {
            $prefix = $name.': ';
            if (str_starts_with($line, $prefix)) {
                return substr($line, strlen($prefix));
            }
        }

        $this->fail("Prompt block has no {$name} field.");
    }
}
