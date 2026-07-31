<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Fiscal;

use App\Support\Fiscal\TaxId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaxIdTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function validDniNieCifProvider(): array
    {
        return [
            ['12345678Z', 'nif'],
            ['00000000T', 'nif'],
            ['B12345674', 'nif'],
            ['ESB12345674', 'nif'],
            ['ES12345678Z', 'nif'],
            ['X1234567L', 'nie'],
            ['Y1234567X', 'nie'],
            ['Z1234567R', 'nie'],
            ['ESX1234567L', 'nie'],
        ];
    }

    #[DataProvider('validDniNieCifProvider')]
    public function test_valid_dni_nie_cif_accepted(string $value, string $type): void
    {
        $this->assertTrue(TaxId::validate($value, $type), "{$value} as {$type}");
    }

    /** @return list<array{0: string, 1: string}> */
    public static function invalidChecksumProvider(): array
    {
        return [
            ['12345678A', 'nif'], // off-by-one letter (Z is correct)
            ['B12345675', 'nif'], // off-by-one CIF digit
            ['X1234567M', 'nie'], // off-by-one NIE letter
            ['Y1234567A', 'nie'],
        ];
    }

    #[DataProvider('invalidChecksumProvider')]
    public function test_invalid_checksums_rejected(string $value, string $type): void
    {
        $this->assertFalse(TaxId::validate($value, $type), "{$value} as {$type}");
    }

    public function test_normalize_strips_and_uppercases(): void
    {
        $this->assertSame('ESB12345678', TaxId::normalize('es-b12345678'));
        $this->assertSame('ESB12345678', TaxId::normalize(' es.b 1234-5678 '));
        $this->assertSame('X1234567L', TaxId::normalize('x1234567l'));
    }

    public function test_siren_siret_luhn(): void
    {
        $this->assertTrue(TaxId::validate('732829320', 'siren'));
        $this->assertTrue(TaxId::validate('73282932000074', 'siret'));
        $this->assertFalse(TaxId::validate('732829321', 'siren'));
        $this->assertFalse(TaxId::validate('73282932000075', 'siret'));
    }

    public function test_vat_uk_crn_other_format_only(): void
    {
        $this->assertTrue(TaxId::validate('ESB12345674', 'vat'));
        $this->assertTrue(TaxId::validate('12345678', 'uk_crn'));
        $this->assertTrue(TaxId::validate('SC123456', 'uk_crn'));
        $this->assertTrue(TaxId::validate('AB12', 'other'));
        $this->assertFalse(TaxId::validate('1', 'vat'));
        $this->assertFalse(TaxId::validate('ABC', 'uk_crn'));
    }
}
