<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\SpokenLocaleDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpokenLocaleDetectorTest extends TestCase
{
    #[Test]
    public function detects_clear_english_spanish_and_french(): void
    {
        $this->assertSame('en', SpokenLocaleDetector::detect('How much does a small unit cost please'));
        $this->assertSame('es', SpokenLocaleDetector::detect('Cuánto cuesta una unidad pequeña por favor'));
        $this->assertSame('fr', SpokenLocaleDetector::detect('Combien coûte une petite unité s\'il vous plaît'));
    }

    #[Test]
    public function short_or_ambiguous_utterances_stay_undetected(): void
    {
        $this->assertNull(SpokenLocaleDetector::detect('ok'));
        $this->assertNull(SpokenLocaleDetector::detect('sí'));
        $this->assertNull(SpokenLocaleDetector::detect('yes'));
        $this->assertNull(SpokenLocaleDetector::detect(''));
        $this->assertNull(SpokenLocaleDetector::detect('thanks'));
    }
}
