<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\SpokenLocaleDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpokenLocaleDetectorTest extends TestCase
{
    #[Test]
    public function english_markers_win_on_a_clear_english_utterance(): void
    {
        $this->assertSame(
            'en',
            SpokenLocaleDetector::detect('so if I wanted the ten square meter one, what would that run me'),
        );
    }

    #[Test]
    public function spanish_markers_win_on_a_clear_spanish_utterance(): void
    {
        $this->assertSame(
            'es',
            SpokenLocaleDetector::detect('hola, quiero una unidad, cuanto cuesta al mes'),
        );
    }

    #[Test]
    public function french_markers_win_on_a_clear_french_utterance(): void
    {
        $this->assertSame(
            'fr',
            SpokenLocaleDetector::detect('bonjour, je voudrais une unité, combien ça coûte par mois'),
        );
    }

    #[Test]
    public function empty_and_unmarked_text_stay_null(): void
    {
        $this->assertNull(SpokenLocaleDetector::detect(''));
        $this->assertNull(SpokenLocaleDetector::detect('   '));
        $this->assertNull(SpokenLocaleDetector::detect('Mohammed Chalani 20'));
    }

    #[Test]
    public function a_delegated_english_query_is_detected_when_utterance_is_missing(): void
    {
        $this->assertSame(
            'en',
            SpokenLocaleDetector::detect('Mohammed Chalani wants to rent a storage unit. Please assist.'),
        );
    }
}
