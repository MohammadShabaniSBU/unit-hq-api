<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\Tools\FactBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FactBagTest extends TestCase
{
    #[Test]
    public function money_forms_match(): void
    {
        $bag = (new FactBag)->money('84.70', 'EUR');

        $this->assertTrue($bag->contains('84.70'));
        $this->assertTrue($bag->contains('84,70'));
        $this->assertTrue($bag->contains('€84.70'));
        $this->assertTrue($bag->contains('€84,70'));
        $this->assertFalse($bag->contains('12.00'));
    }

    #[Test]
    public function merge_keeps_normalised_money(): void
    {
        $bag = (new FactBag)->money('10.00', 'EUR');
        $bag->merge((new FactBag)->money('84.70', 'EUR'));

        $this->assertTrue($bag->contains('84,70'));
        $this->assertTrue($bag->contains('10.00'));
    }

    #[Test]
    public function customer_message_seeds_echo_licence(): void
    {
        $bag = FactBag::fromCustomerMessage('You quoted €84.70 for unit A-114 on 2026-09-01.');

        $this->assertTrue($bag->contains('84,70'));
        $this->assertTrue($bag->contains('A-114'));
        $this->assertTrue($bag->contains('2026-09-01'));
    }
}
