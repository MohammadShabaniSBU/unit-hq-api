<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\CreateOffer;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Offer;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class CreateOfferTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function creates_offer_when_employee_has_permission(): void
    {
        $employee = $this->employeeWithPermission(Permission::OfferManage);
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create(['contact_id' => $contact->id]);

        $result = json_decode((new CreateOffer($employee))->handle(new Request([
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'expires_at' => now()->addDays(7)->format('Y-m-d'),
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('offers', ['deal_id' => $deal->id, 'contact_id' => $contact->id]);
    }

    #[Test]
    public function denies_creation_when_employee_lacks_permission(): void
    {
        $employee = $this->employeeWithoutPermissions();
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create(['contact_id' => $contact->id]);

        $result = json_decode((new CreateOffer($employee))->handle(new Request([
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
            'expires_at' => now()->addDays(7)->format('Y-m-d'),
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertSame(0, Offer::query()->count());
    }
}
