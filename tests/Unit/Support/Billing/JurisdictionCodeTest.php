<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Billing;

use App\Support\Billing\JurisdictionCode;
use PHPUnit\Framework\TestCase;

class JurisdictionCodeTest extends TestCase
{
    public function test_accepts_null_country_and_subdivision(): void
    {
        $this->assertTrue(JurisdictionCode::isValid(null));
        $this->assertTrue(JurisdictionCode::isValid(''));
        $this->assertTrue(JurisdictionCode::isValid('ES'));
        $this->assertTrue(JurisdictionCode::isValid('FR'));
        $this->assertTrue(JurisdictionCode::isValid('ES-CN'));
    }

    public function test_rejects_malformed_codes(): void
    {
        foreach (['esp', 'ES_CN', 'Spain', 'es-cn'] as $code) {
            $this->assertFalse(JurisdictionCode::isValid($code), $code);
        }
    }
}
