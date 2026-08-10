<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Offer;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class NoteControllerTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function contact_manage_alone_no_longer_authorizes_a_note_on_a_deal(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $deal = Deal::factory()->create();

        Sanctum::actingAs($employee);

        $this->postJson('/api/notes', [
            'type' => 'deal',
            'id' => $deal->id,
            'content' => 'Following up next week.',
        ])->assertForbidden();
    }

    #[Test]
    public function deal_manage_authorizes_a_note_on_a_deal(): void
    {
        $employee = $this->employeeWithPermission(Permission::DealManage);
        $deal = Deal::factory()->create();

        Sanctum::actingAs($employee);

        $this->postJson('/api/notes', [
            'type' => 'deal',
            'id' => $deal->id,
            'content' => 'Following up next week.',
        ])->assertCreated();
    }

    #[Test]
    public function offer_is_now_a_supported_note_type(): void
    {
        $employee = $this->employeeWithPermission(Permission::OfferManage);
        $deal = Deal::factory()->create();
        $offer = Offer::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => $deal->contact_id,
        ]);

        Sanctum::actingAs($employee);

        $this->postJson('/api/notes', [
            'type' => 'offer',
            'id' => $offer->id,
            'content' => 'Sent revised pricing.',
        ])->assertCreated();
    }

    #[Test]
    public function contact_manage_authorizes_a_note_on_a_contact(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $contact = Contact::factory()->create();

        Sanctum::actingAs($employee);

        $this->postJson('/api/notes', [
            'type' => 'contact',
            'id' => $contact->id,
            'content' => 'Called about pricing.',
        ])->assertCreated();
    }
}
