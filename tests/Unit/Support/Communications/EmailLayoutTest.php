<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\EmailLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailLayoutTest extends TestCase
{
    public function test_column_widths_sum_exactly(): void
    {
        $pads = [
            'none' => 0,
            'sm' => 12,
            'md' => 24,
            'lg' => 40,
        ];

        foreach (EmailBlockDocument::LAYOUTS as $preset => $count) {
            // PHP casts the numeric-string key "100" to int 100.
            $preset = (string) $preset;
            foreach ($pads as $padding => $pad) {
                $widths = EmailLayout::columnWidths($preset, $padding);
                $inner = EmailLayout::CONTAINER_WIDTH - (2 * $pad);

                $this->assertCount($count, $widths, "{$preset} {$padding}");
                $this->assertSame($inner, array_sum($widths), "{$preset} {$padding}");
                foreach ($widths as $width) {
                    $this->assertGreaterThan(0, $width, "{$preset} {$padding}");
                }
            }
        }

        $this->assertSame([552], EmailLayout::columnWidths('100', 'md'));
        $this->assertSame([276, 276], EmailLayout::columnWidths('50_50', 'md'));
        $this->assertSame([182, 370], EmailLayout::columnWidths('33_67', 'md'));
        $this->assertSame([369, 183], EmailLayout::columnWidths('67_33', 'md'));
        $this->assertSame([184, 184, 184], EmailLayout::columnWidths('33_33_33', 'md'));
        $this->assertSame([173, 173, 174], EmailLayout::columnWidths('33_33_33', 'lg'));
    }

    #[DataProvider('ratioDirectionProvider')]
    public function test_ratio_direction(string $preset, string $relation): void
    {
        foreach (['none', 'sm', 'md', 'lg'] as $padding) {
            $widths = EmailLayout::columnWidths($preset, $padding);
            if ($relation === 'eq') {
                $this->assertLessThanOrEqual(1, abs($widths[0] - $widths[1]));
            } elseif ($relation === 'lt') {
                $this->assertLessThan($widths[1], $widths[0]);
            } else {
                $this->assertGreaterThan($widths[1], $widths[0]);
            }
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ratioDirectionProvider(): array
    {
        return [
            '50_50' => ['50_50', 'eq'],
            '33_67' => ['33_67', 'lt'],
            '67_33' => ['67_33', 'gt'],
        ];
    }
}
