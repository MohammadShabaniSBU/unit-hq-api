<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Support\Automation\RunContext;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\EmailBlockRenderer;
use Tests\TestCase;

/**
 * Golden fixtures target Outlook desktop — see tests/fixtures/communications/email-blocks/README.md.
 */
class BlockRendererTest extends TestCase
{
    public function test_golden_per_block_and_kitchen_sink(): void
    {
        $ctx = new RunContext(subjectBag: [
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

        $cases = [
            'heading' => ['version' => 1, 'blocks' => [['id' => 'h1', 'type' => 'heading', 'params' => ['text' => 'Hello {{contact.first_name}}', 'level' => 1]]]],
            'paragraph' => ['version' => 1, 'blocks' => [['id' => 'p1', 'type' => 'paragraph', 'params' => ['html' => '<p>Dear <strong>{{contact.first_name}}</strong>, welcome.</p>']]]],
            'button' => ['version' => 1, 'blocks' => [['id' => 'b1', 'type' => 'button', 'params' => ['label' => 'Pay now', 'url' => '{{pay_link}}', 'style' => 'primary']]]],
            'button_outline' => ['version' => 1, 'blocks' => [['id' => 'b2', 'type' => 'button', 'params' => ['label' => 'Details', 'url' => 'https://example.com', 'style' => 'outline']]]],
            'image' => ['version' => 1, 'blocks' => [['id' => 'i1', 'type' => 'image', 'params' => ['url' => 'https://cdn.example/logo.png', 'alt' => 'Logo', 'width_percent' => 50]]]],
            'divider' => ['version' => 1, 'blocks' => [['id' => 'd1', 'type' => 'divider', 'params' => []]]],
            'spacer' => ['version' => 1, 'blocks' => [['id' => 's1', 'type' => 'spacer', 'params' => ['height' => 32]]]],
            'unit_summary' => ['version' => 1, 'blocks' => [['id' => 'u1', 'type' => 'unit_summary', 'params' => []]]],
            'raw_html' => ['version' => 1, 'blocks' => [['id' => 'r1', 'type' => 'raw_html', 'params' => ['html' => '<div style="padding:8px">Legacy {{contact.first_name}}</div>']]]],
        ];

        $kitchen = ['version' => 1, 'blocks' => []];
        foreach ($cases as $doc) {
            foreach ($doc['blocks'] as $block) {
                $kitchen['blocks'][] = $block;
            }
        }
        $cases['kitchen_sink'] = $kitchen;

        $dir = base_path('tests/fixtures/communications/email-blocks');

        foreach ($cases as $name => $raw) {
            $doc = EmailBlockDocument::validate($raw);
            $out = EmailBlockRenderer::render($doc, $ctx, '#1d4ed8', false);

            $this->assertStringEqualsFile($dir.'/'.$name.'.html', $out['html'], "HTML golden mismatch for {$name}");
            $this->assertStringEqualsFile($dir.'/'.$name.'.txt', $out['text'], "Text golden mismatch for {$name}");
        }
    }
}
