<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessCredentialMode;
use App\Enums\AccessGrantState;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\SystemEvent;
use App\Support\Access\FakeAccessProvider;
use App\Support\Access\GrantSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PinHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAccessProvider::reset();
    }

    public function test_encrypted_shown_once_never_logged(): void
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $adapter = FakeAccessProvider::make(['api_key' => 'fake_ok']);
        $ref = $adapter->grant(new GrantSpec(
            providerPointId: 'fake-door-al6-06',
            person: ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
            mode: AccessCredentialMode::Pin->value,
        ));

        $this->assertNotNull($ref->pin);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $ref->pin);

        $point = AccessPoint::factory()->create([
            'provider_point_id' => 'fake-door-al6-06',
            'label' => 'Unit AL6-06 door',
        ]);
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create(['contact_id' => $contact->id]);

        $grant = AccessGrant::factory()->create([
            'access_point_id' => $point->id,
            'contact_id' => $contact->id,
            'contract_id' => $contract->id,
            'provider_grant_id' => $ref->providerGrantId,
            'state' => AccessGrantState::Applied,
        ]);
        $grant->storePin((string) $ref->pin);

        $raw = DB::table('access_grants')->where('id', $grant->id)->value('pin');
        $this->assertIsString($raw);
        $this->assertNotSame($ref->pin, $raw);
        $this->assertStringNotContainsString((string) $ref->pin, $raw);

        $first = $grant->revealPinOnce();
        $this->assertSame($ref->pin, $first);
        $this->assertTrue($grant->fresh()->pinWasShown());

        $second = $grant->fresh()->revealPinOnce();
        $this->assertNull($second);

        // Round-trip grant/revoke/list without logging PIN.
        $list = $adapter->listGrants('fake-door-al6-06');
        $this->assertCount(1, $list);
        $adapter->revoke($ref->providerGrantId);
        $this->assertCount(0, $adapter->listGrants('fake-door-al6-06'));

        // Safe log context (no PIN). Assert MessageLogged never carries the PIN.
        Log::info('access.grant.applied', [
            'provider_grant_id' => $ref->providerGrantId,
            'contact_id' => $contact->id,
        ]);

        $this->assertNotEmpty($logged);
        foreach ($logged as $entry) {
            $encoded = json_encode([
                'message' => $entry->message,
                'context' => $entry->context,
            ]) ?: '';
            $this->assertStringNotContainsString((string) $ref->pin, $encoded);
            $this->assertArrayNotHasKey('pin', $entry->context);
        }

        SystemEvent::record('access.grant.applied', $grant, [
            'provider_grant_id' => $ref->providerGrantId,
            'contact_id' => $contact->id,
        ]);

        $tier1 = SystemEvent::query()->where('event', 'access.grant.applied')->first();
        $this->assertNotNull($tier1);
        $payload = json_encode($tier1->payload ?? []) ?: '';
        $this->assertStringNotContainsString((string) $ref->pin, $payload);

        $this->assertNull(
            Activity::query()
                ->where('properties', 'like', '%'.$ref->pin.'%')
                ->first()
        );
    }
}
