<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\AircallUserLink;
use App\Models\CallIntent;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DialTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Contact $contact;

    private string $webhookToken = 'tok-aircall-dial';

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();
        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Call,
            'provider' => Provider::Aircall,
            'is_active' => true,
            'credentials' => [
                'api_id' => 'aircall-id',
                'api_token' => 'aircall-token',
            ],
            'webhook_url_token' => $this->webhookToken,
            'status' => CredentialStatus::Connected,
        ]);

        $this->contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $this->contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15559876543',
            'is_primary' => true,
            'opted_in' => true,
        ]);
    }

    public function test_intent_correlation_exact_and_heuristic(): void
    {
        AircallUserLink::query()->create([
            'employee_id' => $this->employee->id,
            'aircall_user_id' => '456',
            'aircall_user_label' => 'Jane Agent',
        ]);

        $dialCalls = 0;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$dialCalls) {
            if (! str_contains($request->url(), '/users/456/dial')) {
                return Http::response(['ping' => 'pong'], 200);
            }

            $dialCalls++;
            // First dial: body carries call id (exact correlation). Second: 204 (heuristic).
            if ($dialCalls === 1) {
                return Http::response(['call' => ['id' => 812100]], 200);
            }

            return Http::response('', 204);
        });

        $exactDial = $this->postJson('/api/calls/dial', [
            'contact_id' => $this->contact->id,
            'context' => ['type' => 'delinquency', 'id' => 42],
        ])->assertOk();

        $exactIntentId = $exactDial->json('data.id');
        $this->assertDatabaseHas('call_intents', [
            'id' => $exactIntentId,
            'aircall_call_id' => '812100',
            'status' => CallIntent::STATUS_REQUESTED,
            'message_id' => null,
        ]);
        $this->assertSame(0, Message::query()->count());

        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $this->inboundFixture('aircall_call_created_outbound.json'),
        )->assertOk();

        $exactIntent = CallIntent::query()->findOrFail($exactIntentId);
        $this->assertSame(CallIntent::STATUS_CORRELATED, $exactIntent->status);
        $this->assertNotNull($exactIntent->message_id);

        $exactMessage = Message::query()->findOrFail($exactIntent->message_id);
        $this->assertSame('exact', $exactMessage->source_ref['call_intent']['correlation'] ?? null);
        $this->assertSame('delinquency', $exactMessage->source_ref['call_intent']['context_type'] ?? null);
        $this->assertSame(42, $exactMessage->source_ref['call_intent']['context_id'] ?? null);

        $heuristicDial = $this->postJson('/api/calls/dial', [
            'contact_id' => $this->contact->id,
            'to_number' => '+15559876543',
            'context' => ['type' => 'contact', 'id' => $this->contact->id],
        ])->assertOk();

        $heuristicIntentId = $heuristicDial->json('data.id');
        $this->assertNull(CallIntent::query()->findOrFail($heuristicIntentId)->aircall_call_id);

        $payload = $this->inboundFixture('aircall_call_created_outbound.json');
        $payload['data']['id'] = 812200;
        $payload['timestamp'] = time();

        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $payload,
        )->assertOk();

        $heuristicIntent = CallIntent::query()->findOrFail($heuristicIntentId);
        $this->assertSame(CallIntent::STATUS_CORRELATED, $heuristicIntent->status);
        $heuristicMessage = Message::query()->findOrFail($heuristicIntent->message_id);
        $this->assertSame('heuristic', $heuristicMessage->source_ref['call_intent']['correlation'] ?? null);

        // Uncorrelated ages out after 10 minutes.
        $stale = CallIntent::query()->create([
            'employee_id' => $this->employee->id,
            'contact_id' => $this->contact->id,
            'to_number' => '+15551112222',
            'status' => CallIntent::STATUS_REQUESTED,
        ]);
        CallIntent::query()->whereKey($stale->id)->update([
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ]);

        Artisan::call('comms:sweep-uncorrelated-call-intents');

        $this->assertSame(
            CallIntent::STATUS_UNCORRELATED,
            $stale->fresh()?->status,
        );
    }

    public function test_failures_actionable_no_message_synthesis(): void
    {
        // Unmapped caller.
        $this->postJson('/api/calls/dial', [
            'contact_id' => $this->contact->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['not_mapped']);

        $this->assertSame(0, CallIntent::query()->count());
        $this->assertSame(0, Message::query()->count());

        AircallUserLink::query()->create([
            'employee_id' => $this->employee->id,
            'aircall_user_id' => '456',
            'aircall_user_label' => 'Jane Agent',
        ]);

        Http::fake([
            'api.aircall.io/v1/users/456/dial' => Http::response(['error' => 'User not available'], 405),
        ]);

        $this->postJson('/api/calls/dial', [
            'contact_id' => $this->contact->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_offline']);

        $intent = CallIntent::query()->firstOrFail();
        $this->assertSame(CallIntent::STATUS_DIAL_FAILED, $intent->status);
        $this->assertNotNull($intent->error);
        $this->assertNull($intent->message_id);
        $this->assertSame(0, Message::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundFixture(string $name): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/communications/inbound/'.$name)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $data;
    }
}
