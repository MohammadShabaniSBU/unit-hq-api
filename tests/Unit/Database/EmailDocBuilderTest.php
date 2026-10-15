<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Database\Support\EmailDocBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailDocBuilderTest extends TestCase
{
    #[Test]
    public function deterministic_ids(): void
    {
        $first = $this->document('Rate increase notice');
        $second = $this->document('Rate increase notice');
        $other = $this->document('Overdue reminder');

        $this->assertSame($first, $second);
        $this->assertNotSame($first['sections'][0]['id'], $other['sections'][0]['id']);

        $ids = $this->ids($first);
        $this->assertSame($ids, array_values(array_unique($ids)));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^(sec|col|blk)_[0-9a-f]{8}$/', $id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $name): array
    {
        return EmailDocBuilder::make($name)
            ->section('100', fn ($column) => $column->heading('Title', 1)->paragraph('<p>Hi</p>'))
            ->section(
                '50_50',
                fn ($column) => $column->paragraph('<p>Left</p>'),
                fn ($column) => $column->paragraph('<p>Right</p>'),
            )
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<string>
     */
    private function ids(array $doc): array
    {
        $ids = [];
        foreach ($doc['sections'] as $section) {
            $ids[] = $section['id'];
            foreach ($section['columns'] as $column) {
                $ids[] = $column['id'];
                foreach ($column['blocks'] as $block) {
                    $ids[] = $block['id'];
                }
            }
        }

        return $ids;
    }
}
