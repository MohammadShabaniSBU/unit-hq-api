<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use Tests\TestCase;

class NudgeAuditTest extends TestCase
{
    public function test_every_writer_nudges(): void
    {
        $writers = [
            app_path('Models/AccessSuspension.php'),
            app_path('Support/Contracts/ContractSigning.php'),
            app_path('Http/Controllers/Concerns/VacatesContracts.php'),
            app_path('Support/Delinquency/Overlock.php'),
            app_path('Support/Contracts/ContractTransition.php'),
            app_path('Support/Contracts/ActivatePendingContracts.php'),
            app_path('Http/Controllers/Concerns/TransfersContracts.php'),
        ];

        foreach ($writers as $path) {
            $this->assertFileExists($path);
            $source = file_get_contents($path);
            $this->assertNotFalse($source);
            $this->assertStringContainsString(
                'AccessSync::nudge',
                $source,
                "Fact writer missing AccessSync::nudge: {$path}",
            );
        }
    }
}
