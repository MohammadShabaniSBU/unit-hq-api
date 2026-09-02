<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\VoiceBridgeCustomerConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportVoiceBridgeConfigCommandTest extends TestCase
{
    #[Test]
    public function writes_the_committed_snapshot(): void
    {
        $this->artisan('agents:export-voice-bridge-config')
            ->expectsOutputToContain(VoiceBridgeCustomerConfig::RELATIVE_PATH)
            ->assertSuccessful();

        $this->assertSame(
            VoiceBridgeCustomerConfig::encoded(),
            file_get_contents(VoiceBridgeCustomerConfig::path())
        );
    }
}
