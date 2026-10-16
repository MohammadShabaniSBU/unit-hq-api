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
        $this->assertSame('J7', DemoUnitNumber::format('XS7', 7));
        $this->assertSame('AP10', DemoUnitNumber::format('XL16', 10));
        $this->assertSame('G1', DemoUnitNumber::format('XS5', 1));
        $this->assertSame('A1', DemoUnitNumber::format('XS1.5', 1));
        $this->assertSame('B74', DemoUnitNumber::format('XS2', 74));
        $this->assertSame('D16', DemoUnitNumber::format('XS3', 16));
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
        $this->assertTrue(DemoUnitNumber::isCurrentFormat('XS7', 'J7'));
        $this->assertTrue(DemoUnitNumber::isCurrentFormat('XL16', 'AP10'));
        $this->assertTrue(DemoUnitNumber::isCurrentFormat('XS2', 'B74'));
        $this->assertFalse(DemoUnitNumber::isCurrentFormat('XS2', 'B75'));
        $this->assertFalse(DemoUnitNumber::isCurrentFormat('XS7', 'MAD-01-XS7-07'));
        $this->assertFalse(DemoUnitNumber::isCurrentFormat('XS7', 'K7'));
    }

    #[Test]
    public function parses_legacy_site_class_suffix(): void
    {
        $this->assertSame(7, DemoUnitNumber::parseLegacyIndex('MAD-01', 'XS7', 'MAD-01-XS7-07'));
        $this->assertSame(1, DemoUnitNumber::parseLegacyIndex('MAD-01', 'XS7', 'MAD-01-XS7-01'));
        $this->assertSame(10, DemoUnitNumber::parseLegacyIndex('MAD-02', 'XL16', 'MAD-02-XL16-10'));
        $this->assertNull(DemoUnitNumber::parseLegacyIndex('MAD-01', 'XS7', 'J7'));
        $this->assertNull(DemoUnitNumber::parseLegacyIndex('MAD-01', 'XS7', 'MAD-01-XS7-11'));
        $this->assertNull(DemoUnitNumber::parseLegacyIndex('MAD-01', 'XS7', 'MAD-02-XS7-07'));
    }
}
