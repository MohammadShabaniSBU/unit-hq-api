<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\CreateReservation;
use App\Models\Contact;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\UnitClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

/**
 * Only the permission gate is covered here — a happy-path reservation needs a
 * full site/unit-class/rate/price/unit graph that no fixture in this repo
 * currently builds end to end. The gate itself doesn't depend on that graph:
 * it runs before any pricing/unit lookup.
 */
class CreateReservationTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function denies_creation_when_employee_lacks_permission(): void
    {
        $employee = $this->employeeWithoutPermissions();
        $contact = Contact::factory()->create();
        $site = Site::factory()->create();
        $unitClass = UnitClass::factory()->create();

        $result = json_decode((new CreateReservation($employee))->handle(new Request([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'contact_id' => $contact->id,
            'expires_at' => now()->addDays(3)->format('Y-m-d'),
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertSame(0, Reservation::query()->count());
    }
}
