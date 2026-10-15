<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\EmailBlockDocument;
use Tests\TestCase;

class EmailBlockUpcastTest extends TestCase
{
    public function test_v1_to_single_section_deterministic(): void
    {
        $v1 = [
            'version' => 1,
            'blocks' => [
                ['id' => 'p1', 'type' => 'paragraph', 'params' => ['html' => '<p>Hi</p>']],
                ['id' => 'blk_abcdef12', 'type' => 'divider', 'params' => []],
            ],
        ];
        $before = $v1;

        $first = EmailBlockDocument::upcast($v1);
        $second = EmailBlockDocument::upcast($v1);

        $this->assertSame($before, $v1);
        $this->assertSame($first, $second);
        $this->assertSame(EmailBlockDocument::VERSION_2, $first['version']);
        $this->assertSame('100', $first['sections'][0]['layout']);
        $this->assertTrue($first['sections'][0]['params']['stack_on_mobile']);
        $this->assertSame('md', $first['sections'][0]['params']['padding']);
        $this->assertNull($first['settings']['background_color']);

        $blocks = $first['sections'][0]['columns'][0]['blocks'];
        $this->assertSame('blk_'.substr(sha1('blk:p1'), 0, 8), $blocks[0]['id']);
        $this->assertSame('paragraph', $blocks[0]['type']);
        $this->assertSame('<p>Hi</p>', $blocks[0]['params']['html']);
        $this->assertSame('blk_abcdef12', $blocks[1]['id']);
        $this->assertSame('sec_'.substr(sha1('sec:p1'), 0, 8), $first['sections'][0]['id']);
        $this->assertSame('col_'.substr(sha1('col:p1'), 0, 8), $first['sections'][0]['columns'][0]['id']);

        $validated = EmailBlockDocument::validate($first);
        $this->assertSame($first['sections'][0]['id'], $validated['sections'][0]['id']);
    }

    public function test_empty_v1_uses_stable_empty_seed(): void
    {
        $doc = EmailBlockDocument::upcast(['version' => 1, 'blocks' => []]);

        $this->assertSame('sec_'.substr(sha1('sec:'), 0, 8), $doc['sections'][0]['id']);
        $this->assertSame('col_'.substr(sha1('col:'), 0, 8), $doc['sections'][0]['columns'][0]['id']);
        $this->assertSame([], $doc['sections'][0]['columns'][0]['blocks']);
        EmailBlockDocument::validate($doc);
    }
}
