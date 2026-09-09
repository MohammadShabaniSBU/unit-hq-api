<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai\Tools;

use App\Support\Ai\Tools\MoneyDisplay;
use App\Support\Billing\TaxBreakdown;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyDisplayTest extends TestCase
{
    #[Test]
    public function spoken_quote_is_one_gross_figure_without_the_site(): void
    {
        $breakdown = new TaxBreakdown(net: '70.00', tax: '14.70', gross: '84.70');

        $this->assertSame(
            'Small, €84.70 per month, tax included.',
            MoneyDisplay::spokenQuote($breakdown, 'EUR', 'en', 'per month', 'Small'),
        );
        $this->assertSame(
            'Small, €84,70 per month, con IVA incluido.',
            MoneyDisplay::spokenQuote($breakdown, 'EUR', 'es', 'per month', 'Small'),
        );
    }

    #[Test]
    public function written_quote_still_carries_the_net_breakdown_and_site(): void
    {
        $breakdown = new TaxBreakdown(net: '70.00', tax: '14.70', gross: '84.70');

        $this->assertSame(
            '€70.00 net / €84.70 incl. 21% tax, per month — Small at Madrid Centro',
            MoneyDisplay::quote($breakdown, 'EUR', 'en', '21.00', 'per month', 'Small', 'Madrid Centro'),
        );
    }
}
