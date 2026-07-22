<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ContactLifecycleStatus;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactStatusCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_counts_returns_every_enum_key_including_zeros(): void
    {
        Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        Contact::factory()->create(['status' => ContactLifecycleStatus::Prospect]);
        Contact::factory()->create(['status' => ContactLifecycleStatus::Lead]);

        $counts = Contact::statusCounts();

        $this->assertSame([
            'prospect' => 2,
            'lead' => 1,
            'opportunity' => 0,
            'tenant' => 0,
            'past_tenant' => 0,
            'lost' => 0,
        ], $counts);
    }

    public function test_status_counts_respects_search(): void
    {
        Contact::factory()->create([
            'first_name' => 'Alice',
            'company' => 'Acme Storage',
            'status' => ContactLifecycleStatus::Prospect,
        ]);
        Contact::factory()->create([
            'first_name' => 'Bob',
            'company' => 'Other Co',
            'status' => ContactLifecycleStatus::Prospect,
        ]);
        Contact::factory()->create([
            'first_name' => 'Carol',
            'company' => 'Acme West',
            'status' => ContactLifecycleStatus::Lead,
        ]);

        $counts = Contact::statusCounts('Acme');

        $this->assertSame(1, $counts['prospect']);
        $this->assertSame(1, $counts['lead']);
        $this->assertSame(0, $counts['opportunity']);
    }
}
