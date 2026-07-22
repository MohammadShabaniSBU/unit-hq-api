<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContactLifecycleStatus;
use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_index_returns_one_column_per_status_in_enum_order(): void
    {
        Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        Contact::factory()->create(['status' => ContactLifecycleStatus::Lead]);
        Contact::factory()->create(['status' => ContactLifecycleStatus::Lead]);

        $response = $this->getJson('/api/contacts/board?per_column=25');

        $response->assertOk();

        $columns = $response->json('data.columns');
        $this->assertCount(6, $columns);
        $this->assertSame(
            ['prospect', 'lead', 'opportunity', 'tenant', 'past_tenant', 'lost'],
            array_column($columns, 'status')
        );
        $this->assertSame(1, $columns[0]['total']);
        $this->assertSame(2, $columns[1]['total']);
        $this->assertCount(1, $columns[0]['cards']);
        $this->assertCount(2, $columns[1]['cards']);
        $this->assertFalse($columns[0]['has_more']);
        $this->assertNull($columns[0]['next_cursor']);
    }

    public function test_board_index_respects_per_column_and_has_more(): void
    {
        Contact::factory()->count(3)->create(['status' => ContactLifecycleStatus::Prospect]);

        $response = $this->getJson('/api/contacts/board?per_column=2');

        $response->assertOk();

        $prospect = $response->json('data.columns.0');
        $this->assertSame(3, $prospect['total']);
        $this->assertCount(2, $prospect['cards']);
        $this->assertTrue($prospect['has_more']);
        $this->assertNotNull($prospect['next_cursor']);
    }

    public function test_board_column_paginates_via_cursor(): void
    {
        Contact::factory()->count(3)->create(['status' => ContactLifecycleStatus::Lead]);

        $first = $this->getJson('/api/contacts/board/columns/lead?per_column=2');
        $first->assertOk();
        $this->assertCount(2, $first->json('data.cards'));
        $this->assertTrue($first->json('data.has_more'));

        $cursor = $first->json('data.next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->getJson('/api/contacts/board/columns/lead?per_column=2&cursor='.$cursor);
        $second->assertOk();
        $this->assertCount(1, $second->json('data.cards'));
        $this->assertFalse($second->json('data.has_more'));
        $this->assertNull($second->json('data.next_cursor'));

        $firstIds = collect($first->json('data.cards'))->pluck('id');
        $secondIds = collect($second->json('data.cards'))->pluck('id');
        $this->assertEmpty($firstIds->intersect($secondIds));
    }

    public function test_board_column_unknown_status_returns_404(): void
    {
        $this->getJson('/api/contacts/board/columns/not_a_status')
            ->assertNotFound();
    }

    public function test_board_column_clamps_per_column(): void
    {
        Contact::factory()->count(3)->create(['status' => ContactLifecycleStatus::Tenant]);

        $tooLow = $this->getJson('/api/contacts/board/columns/tenant?per_column=0');
        $tooLow->assertOk();
        $this->assertCount(1, $tooLow->json('data.cards'));

        Contact::factory()->count(100)->create(['status' => ContactLifecycleStatus::Lost]);
        $tooHigh = $this->getJson('/api/contacts/board/columns/lost?per_column=500');
        $tooHigh->assertOk();
        $this->assertCount(100, $tooHigh->json('data.cards'));
        $this->assertSame(100, $tooHigh->json('data.total'));
    }

    public function test_update_status_returns_card_resource_and_logs_crm_diff(): void
    {
        $contact = Contact::factory()->create([
            'status' => ContactLifecycleStatus::Prospect,
        ]);

        $response = $this->patchJson("/api/contacts/{$contact->id}/status", [
            'status' => ContactLifecycleStatus::Opportunity->value,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $contact->id)
            ->assertJsonPath('data.status', 'opportunity')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'first_name',
                    'last_name',
                    'company',
                    'email',
                    'status',
                    'deals_count',
                    'updated_at',
                ],
            ]);

        $this->assertSame(
            ContactLifecycleStatus::Opportunity,
            $contact->fresh()->status
        );

        $activity = Activity::query()
            ->where('subject_id', $contact->id)
            ->where('log_name', LogChannel::Crm->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties->toArray();
        $this->assertSame('prospect', data_get($properties, 'old.status') ?? data_get($properties, 'old.status.value'));
        $this->assertSame('opportunity', data_get($properties, 'attributes.status') ?? data_get($properties, 'attributes.status.value'));
    }

    public function test_update_status_rejects_invalid_enum(): void
    {
        $contact = Contact::factory()->create();

        $this->patchJson("/api/contacts/{$contact->id}/status", [
            'status' => 'not_valid',
        ])->assertUnprocessable();
    }

    public function test_board_is_not_site_scoped(): void
    {
        // Contacts have no site_id; board must still return every working contact.
        Contact::factory()->create([
            'first_name' => 'SiteAgnostic',
            'status' => ContactLifecycleStatus::Prospect,
        ]);

        $response = $this->getJson('/api/contacts/board');

        $response->assertOk();
        $names = collect($response->json('data.columns.0.cards'))->pluck('first_name');
        $this->assertTrue($names->contains('SiteAgnostic'));
    }
}
