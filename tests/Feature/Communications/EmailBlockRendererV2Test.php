<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Setting;
use App\Models\TemplateAsset;
use App\Support\Automation\RunContext;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\EmailBlockRenderer;
use Database\Seeders\SocialIconSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailBlockRendererV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_golden_files(): void
    {
        $dir = base_path('tests/Fixtures/email-render/v2');
        $files = glob($dir.'/*.json');
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($raw, $file);
            $name = basename($file, '.json');
            $version = $raw['version'] ?? null;
            $doc = ($version === 2 || $version === '2')
                ? EmailBlockDocument::validate($raw)
                : $raw;

            $out = EmailBlockRenderer::render($doc, $this->context(), '#1d4ed8', false);

            $this->assertStringEqualsFile($dir.'/'.$name.'.html', $out['html'], "HTML golden mismatch for {$name}");
            $this->assertStringEqualsFile($dir.'/'.$name.'.txt', $out['text'], "Text golden mismatch for {$name}");
        }
    }

    public function test_v1_through_upcast(): void
    {
        $v1 = [
            'version' => 1,
            'blocks' => [
                ['id' => 'h1', 'type' => 'heading', 'params' => ['text' => 'Hello', 'level' => 1]],
                ['id' => 'p1', 'type' => 'paragraph', 'params' => ['html' => '<p>Body</p>']],
            ],
        ];

        $direct = EmailBlockRenderer::render($v1, $this->context(), '#1d4ed8', false);
        $upcast = EmailBlockRenderer::render(EmailBlockDocument::upcast($v1), $this->context(), '#1d4ed8', false);

        $this->assertSame($upcast['html'], $direct['html']);
        $this->assertSame($upcast['text'], $direct['text']);
        $this->assertSame("Hello\n\nBody", $direct['text']);
    }

    public function test_stack_on_mobile_false_omits_class(): void
    {
        $stacked = $this->renderLayout(true);
        $flat = $this->renderLayout(false);

        $this->assertContains('kv-col', $this->classTokens($stacked));
        $tokens = $this->classTokens($flat);
        $this->assertNotContains('kv-col', $tokens);
        $this->assertContains('kv-col-50_50', $tokens);
    }

    public function test_text_reading_order(): void
    {
        $out = EmailBlockRenderer::render($this->twoColumnDoc(true, 'Left', 'Right'), $this->context(), '#1d4ed8', false);

        $this->assertSame("Left\n\nRight", $out['text']);
    }

    public function test_preview_marker_inside_column(): void
    {
        $doc = EmailBlockDocument::validate([
            'version' => 2,
            'sections' => [[
                'id' => 'sec_abcd1234',
                'layout' => '100',
                'params' => ['background_color' => null, 'padding' => 'md', 'stack_on_mobile' => true],
                'columns' => [[
                    'id' => 'col_abcd1234',
                    'blocks' => [[
                        'id' => 'blk_abcd1234',
                        'type' => 'heading',
                        'params' => ['text' => '{{contact.missing}}', 'level' => 1],
                    ]],
                ]],
            ]],
        ]);

        $html = EmailBlockRenderer::render($doc, $this->context(), '#1d4ed8', true)['html'];
        $marker = '⟦missing:contact.missing⟧';
        $div = strpos($html, '<div class="kv-col kv-col-100"');
        $mark = strpos($html, $marker);

        $this->assertNotFalse($div);
        $this->assertNotFalse($mark);
        $this->assertGreaterThan($div, $mark);
    }

    public function test_new_blocks_golden(): void
    {
        config(['app.url' => 'http://assets.test']);
        $this->seed(SocialIconSeeder::class);
        $this->storeLogo();

        $dir = base_path('tests/Fixtures/email-render/v2/blocks');
        $files = glob($dir.'/*.json');
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $raw = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($raw, $file);
            $name = basename($file, '.json');
            $doc = EmailBlockDocument::validate($raw);
            $out = EmailBlockRenderer::render($doc, $this->blockContext(), '#1d4ed8', false);

            $htmlPath = $dir.'/'.$name.'.html';
            $txtPath = $dir.'/'.$name.'.txt';

            $this->assertStringEqualsFile($htmlPath, $out['html'], "HTML golden mismatch for {$name}");
            $this->assertStringEqualsFile($txtPath, $out['text'], "Text golden mismatch for {$name}");
        }
    }

    public function test_logo_header_without_logo(): void
    {
        $doc = EmailBlockDocument::validate([
            'version' => 2,
            'sections' => [[
                'id' => 'sec_d0e0f001',
                'layout' => '100',
                'params' => ['background_color' => null, 'padding' => 'md', 'stack_on_mobile' => true],
                'columns' => [[
                    'id' => 'col_d0e0f001',
                    'blocks' => [[
                        'id' => 'blk_d0e0f001',
                        'type' => 'logo_header',
                        'params' => ['align' => 'center', 'max_width' => 120, 'link_url' => null],
                    ]],
                ]],
            ]],
        ]);

        $send = EmailBlockRenderer::render($doc, $this->context(), '#1d4ed8', false);
        $preview = EmailBlockRenderer::render($doc, $this->context(), '#1d4ed8', true);

        $this->assertStringNotContainsString('⟦missing:brand.logo⟧', $send['html']);
        $this->assertStringNotContainsString('<img', $send['html']);
        $this->assertSame('', $send['text']);
        $this->assertStringContainsString('⟦missing:brand.logo⟧', $preview['html']);
        $this->assertSame('⟦missing:brand.logo⟧', $preview['text']);
    }

    private function renderLayout(bool $stack): string
    {
        return EmailBlockRenderer::render(
            $this->twoColumnDoc($stack, 'Left', 'Right'),
            $this->context(),
            '#1d4ed8',
            false,
        )['html'];
    }

    /**
     * @return array<string, mixed>
     */
    private function twoColumnDoc(bool $stack, string $left, string $right): array
    {
        return EmailBlockDocument::validate([
            'version' => 2,
            'sections' => [[
                'id' => 'sec_5050abcd',
                'layout' => '50_50',
                'params' => ['background_color' => null, 'padding' => 'md', 'stack_on_mobile' => $stack],
                'columns' => [
                    [
                        'id' => 'col_5050abc1',
                        'blocks' => [[
                            'id' => 'blk_5050abc1',
                            'type' => 'heading',
                            'params' => ['text' => $left, 'level' => 2],
                        ]],
                    ],
                    [
                        'id' => 'col_5050abc2',
                        'blocks' => [[
                            'id' => 'blk_5050abc2',
                            'type' => 'heading',
                            'params' => ['text' => $right, 'level' => 2],
                        ]],
                    ],
                ],
            ]],
        ]);
    }

    /**
     * @return list<string>
     */
    private function classTokens(string $html): array
    {
        preg_match_all('/<div class="([^"]*)"/', $html, $matches);
        $tokens = [];
        foreach ($matches[1] as $class) {
            foreach (preg_split('/\s+/', $class) ?: [] as $token) {
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    private function context(): RunContext
    {
        return new RunContext(subjectBag: [
            'contact' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
            ],
            'contract' => [
                'unit_name' => 'A-101',
                'unit_rate' => '89.00',
                'currency' => 'EUR',
            ],
            'pay_link' => 'https://pay.example/x',
        ]);
    }

    private function blockContext(): RunContext
    {
        return new RunContext(subjectBag: [
            'contact' => [
                'name' => 'Ada Lovelace',
            ],
            'contract' => [
                'balance_owed' => '120.50',
                'currency' => 'EUR',
            ],
            'pay_link' => 'https://pay.example/x',
        ]);
    }

    private function storeLogo(): void
    {
        $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $hash = hash('sha256', $bytes);
        $asset = TemplateAsset::query()->create([
            'hash' => $hash,
            'disk_path' => $hash.'/logo.png',
            'original_filename' => 'logo.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'created_by' => null,
        ]);

        Setting::setGeneral(Setting::general()->with(emailLogoAssetId: $asset->id));
    }
}
