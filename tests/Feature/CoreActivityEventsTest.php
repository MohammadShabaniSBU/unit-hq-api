<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\LogChannel;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Activity;
use App\Support\RecordsActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreActivityEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_store_logs_core_created_event(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/deals', [
            'contact_id' => $contact->id,
            'status' => DealStatus::New->value,
        ]);

        $response->assertCreated();
        $requestId = $response->headers->get('X-Request-Id');
        $this->assertNotEmpty($requestId);

        $activity = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'deal.created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(Deal::class, $activity->subject_type);
        $this->assertSame($requestId, $activity->properties->get('request_id'));
    }

    public function test_deal_stage_change_logs_core_events(): void
    {
        $contact = Contact::factory()->create();
        $deal = Deal::query()->create([
            'contact_id' => $contact->id,
            'status' => DealStatus::New,
        ]);

        $response = $this->patchJson('/api/deals/'.$deal->id, [
            'status' => DealStatus::ClosedWon->value,
        ]);

        $response->assertOk();

        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'deal.stage_changed')
                ->where('subject_id', $deal->id)
                ->exists()
        );
        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'deal.won')
                ->where('subject_id', $deal->id)
                ->exists()
        );
    }

    public function test_rolled_back_transaction_leaves_no_core_activity_row(): void
    {
        $contact = Contact::factory()->create();
        $deal = Deal::query()->create([
            'contact_id' => $contact->id,
            'status' => DealStatus::New,
        ]);

        try {
            DB::transaction(function () use ($deal): void {
                RecordsActivity::core('deal.won', $deal, ['status' => 'closed_won']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, Activity::query()->where('description', 'deal.won')->count());
    }
}
