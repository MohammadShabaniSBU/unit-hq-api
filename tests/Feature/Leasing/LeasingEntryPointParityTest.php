<?php

declare(strict_types=1);

namespace Tests\Feature\Leasing;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HTTP controllers must create offers/reservations only via App\Support\Leasing.
 * Copilot tools stay on an allowlist until they move onto those entry points (S25).
 * database/seeders/ is deliberately out of scope.
 */
class LeasingEntryPointParityTest extends TestCase
{
    /**
     * S25 removes this when Copilot tools call App\Support\Leasing.
     *
     * @var list<string>
     */
    private const COPILOT_ALLOWLIST = [
        'Ai/Tools/CreateOffer.php',
        'Ai/Tools/CreateReservation.php',
    ];

    /** @var list<string> */
    private const CONTROLLER_FILES = [
        'Http/Controllers/OfferController.php',
        'Http/Controllers/OfferOptionController.php',
        'Http/Controllers/ReservationController.php',
    ];

    /** @var list<string> */
    private const CONTROLLER_FORBIDDEN = [
        'Offer::query()->create',
        'Reservation::query()->create',
        '->options()->create',
    ];

    #[Test]
    public function no_outsider_creates_offers_or_reservations_except_allowlisted_copilot_tools(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if (str_starts_with($relative, 'Support/Leasing/')) {
                continue;
            }

            if (in_array($relative, self::COPILOT_ALLOWLIST, true)) {
                continue;
            }

            $contents = $file->getContents();
            foreach (['Offer::query()->create', 'Reservation::query()->create'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = "{$relative} contains {$needle}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Files outside App\\Support\\Leasing\\ must not create Offer/Reservation rows except the Copilot allowlist:\n"
            .implode("\n", $offenders),
        );
    }

    #[Test]
    public function controllers_do_not_contain_forbidden_create_needles(): void
    {
        foreach (self::CONTROLLER_FILES as $relative) {
            $contents = File::get(app_path($relative));
            foreach (self::CONTROLLER_FORBIDDEN as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "{$relative} must not contain {$needle}",
                );
            }
        }
    }

    #[Test]
    public function writes_reservation_holds_is_a_write_only_shim(): void
    {
        $path = app_path('Http/Controllers/Concerns/WritesReservationHolds.php');
        $this->assertFileExists($path);

        $contents = File::get($path);
        $this->assertStringContainsString('ReservationHolds::write', $contents);
        $this->assertStringNotContainsString('OccupancyGuard', $contents);
        $this->assertStringNotContainsString('HoldGuard', $contents);
        $this->assertStringNotContainsString('function releaseReservationHold', $contents);
        $this->assertStringContainsString('@deprecated', $contents);
    }

    #[Test]
    public function leasing_support_classes_are_not_a_services_layer(): void
    {
        $this->assertDirectoryDoesNotExist(app_path('Services'));

        foreach (File::allFiles(app_path('Support/Leasing')) as $file) {
            $this->assertStringNotContainsString(
                'interface ',
                $file->getContents(),
                $file->getRelativePathname().' must not introduce an interface',
            );
        }

        $provider = File::get(app_path('Providers/AppServiceProvider.php'));
        foreach ([
            'OfferCreation',
            'OfferAcceptance',
            'ReservationCreation',
            'ReservationHolds',
            'LeasingActor',
        ] as $class) {
            $this->assertStringNotContainsString(
                $class,
                $provider,
                "AppServiceProvider must not bind {$class}",
            );
        }
    }
}
