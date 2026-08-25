<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Support\Communications\Gsm7Transliterator;
use App\Support\Communications\Messages\SmsMessage;
use Tests\TestCase;

class Gsm7TransliteratorTest extends TestCase
{
    public function test_maps_spec_characters_and_inserts_eur_space(): void
    {
        $applied = Gsm7Transliterator::apply('10 m² — €346.80… “quote”');

        $this->assertTrue($applied['changed']);
        $this->assertSame('10 m2 - EUR 346.80... "quote"', $applied['body']);
    }

    public function test_leaves_spanish_gsm7_characters_untouched(): void
    {
        $spanish = 'café año ¿qué? ¡hola!';
        $applied = Gsm7Transliterator::apply($spanish);

        $this->assertFalse($applied['changed']);
        $this->assertSame($spanish, $applied['body']);
        $this->assertSame('gsm7', (new SmsMessage('+1', $applied['body']))->encoding());
    }

    public function test_leaves_ordinals_untouched_even_when_they_force_ucs2(): void
    {
        $applied = Gsm7Transliterator::apply('1º 2ª');

        $this->assertFalse($applied['changed']);
        $this->assertSame('1º 2ª', $applied['body']);
        $this->assertSame('ucs2', (new SmsMessage('+1', $applied['body']))->encoding());
    }

    public function test_replaces_nbsp_variants_with_space(): void
    {
        $applied = Gsm7Transliterator::apply("10\u{00A0}m²\u{202F}wide");

        $this->assertTrue($applied['changed']);
        $this->assertSame('10 m2 wide', $applied['body']);
    }

    public function test_is_noop_when_body_is_already_gsm7(): void
    {
        $applied = Gsm7Transliterator::apply('Hello from Madrid Centro.');

        $this->assertFalse($applied['changed']);
        $this->assertSame('Hello from Madrid Centro.', $applied['body']);
    }
}
