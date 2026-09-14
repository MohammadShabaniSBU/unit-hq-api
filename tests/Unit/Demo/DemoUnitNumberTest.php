<?php

declare(strict_types=1);

namespace Tests\Unit\Demo;

use Database\Seeders\Demo\DemoUnitNumber;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class DemoUnitNumberTest extends TestCase
{
    #[Test]
    public function formats_standard_and_xl_classes(): void
    {
        $this->assertSame('J7', DemoUnitNumber::format('SS3', 7));
        $this->assertSame('AP10', DemoUnitNumber::format('AL4', 10));
        $this->assertSame('G1', DemoUnitNumber::format('SS1', 1));
    }

    #[Test]
    public function unknown_class_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No demo unit-number prefix for class XX1.');

        DemoUnitNumber::format('XX1', 1);
    }

    #[Test]
    public function detects_current_short_format(): void
    {
        $this->assertTrue(DemoUnitNumber::isCurrentFormat('SS3', 'J7'));
        $this->assertTrue(DemoUnitNumber::isCurrentFormat('AL4', 'AP10'));
        $this->assertFalse(DemoUnitNumber::isCurrentFormat('SS3', 'MAD-01-SS3-07'));
        $this->assertFalse(DemoUnitNumber::isCurrentFormat('SS3', 'K7'));
    }

    #[Test]
    public function parses_legacy_site_class_suffix(): void
    {
        $this->assertSame(7, DemoUnitNumber::parseLegacyIndex('MAD-01', 'SS3', 'MAD-01-SS3-07'));
        $this->assertSame(1, DemoUnitNumber::parseLegacyIndex('MAD-01', 'SS3', 'MAD-01-SS3-01'));
        $this->assertSame(10, DemoUnitNumber::parseLegacyIndex('MAD-02', 'AL4', 'MAD-02-AL4-10'));
        $this->assertNull(DemoUnitNumber::parseLegacyIndex('MAD-01', 'SS3', 'J7'));
        $this->assertNull(DemoUnitNumber::parseLegacyIndex('MAD-01', 'SS3', 'MAD-01-SS3-11'));
        $this->assertNull(DemoUnitNumber::parseLegacyIndex('MAD-01', 'SS3', 'MAD-02-SS3-07'));
    }
}
