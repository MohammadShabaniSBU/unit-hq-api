<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\EmailBlockDocument;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmailBlockParamsTest extends TestCase
{
    public function test_info_rows(): void
    {
        $doc = EmailBlockDocument::validate($this->document('info_rows', [
            'rows' => [
                ['label' => 'Balance', 'value' => '{{contract.balance_owed}}'],
            ],
            'striped' => true,
            'value_align' => 'right',
        ]));

        $this->assertSame('info_rows', $doc['sections'][0]['columns'][0]['blocks'][0]['type']);

        $rows = [];
        for ($i = 0; $i < 13; $i++) {
            $rows[] = ['label' => 'L'.$i, 'value' => 'V'.$i];
        }

        $this->assertRejects($this->document('info_rows', [
            'rows' => $rows,
            'striped' => false,
            'value_align' => 'left',
        ]));
    }

    public function test_list(): void
    {
        EmailBlockDocument::validate($this->document('list', [
            'style' => 'numbered',
            'items' => ['One', '<strong>Two</strong>'],
        ]));

        $this->assertRejects($this->document('list', [
            'style' => 'bullet',
            'items' => [],
        ]));
    }

    public function test_callout(): void
    {
        EmailBlockDocument::validate($this->document('callout', [
            'tone' => 'danger',
            'title' => null,
            'html' => '<p>Lien</p>',
        ]));

        $this->assertRejects($this->document('callout', [
            'tone' => 'urgent',
            'title' => 'No',
            'html' => '<p>No</p>',
        ]));
    }

    public function test_logo_header(): void
    {
        EmailBlockDocument::validate($this->document('logo_header', [
            'align' => 'center',
            'max_width' => 120,
            'link_url' => 'https://example.test',
        ]));

        $this->assertRejects($this->document('logo_header', [
            'align' => 'left',
            'max_width' => 40,
            'link_url' => null,
        ]));
    }

    public function test_footer_social(): void
    {
        EmailBlockDocument::validate($this->document('footer_social', [
            'links' => [],
            'address' => null,
        ]));

        $links = [];
        foreach (['facebook', 'instagram', 'linkedin', 'x', 'youtube', 'tiktok', 'website'] as $network) {
            $links[] = ['network' => $network, 'url' => 'https://example.test/'.$network];
        }

        $this->assertRejects($this->document('footer_social', [
            'links' => $links,
            'address' => null,
        ]));
    }

    public function test_url_scheme_allowlist(): void
    {
        foreach (['https://example.test', 'mailto:ada@example.com', 'tel:+341234', '{{pay_link}}'] as $url) {
            EmailBlockDocument::validate($this->document('button', [
                'label' => 'Pay',
                'url' => $url,
                'style' => 'primary',
            ]));
        }

        foreach (['javascript:alert(1)', 'http://example.test', '{{contact.email}}'] as $url) {
            try {
                EmailBlockDocument::validate($this->document('button', [
                    'label' => 'Pay',
                    'url' => $url,
                    'style' => 'primary',
                ]));
                $this->fail($url.' was accepted');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    [__('errors.templates.link_invalid_scheme')],
                    $exception->errors()['blocks.sections.0.columns.0.blocks.0.params.url'],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function document(string $type, array $params): array
    {
        return [
            'version' => 2,
            'sections' => [[
                'id' => 'sec_abcd1234',
                'layout' => '100',
                'params' => ['background_color' => null, 'padding' => 'md', 'stack_on_mobile' => true],
                'columns' => [[
                    'id' => 'col_abcd1234',
                    'blocks' => [[
                        'id' => 'blk_abcd1234',
                        'type' => $type,
                        'params' => $params,
                    ]],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function assertRejects(array $doc): void
    {
        try {
            EmailBlockDocument::validate($doc);
            $this->fail('Document was accepted');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
