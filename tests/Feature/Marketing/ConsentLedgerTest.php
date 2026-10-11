<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Events\ChannelDeliveryFailed;
use App\Models\Activity;
use App\Models\ChannelSuppression;
use App\Models\CommunicationAccount;
use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\InboundReceiptApplier;
use App\Support\Communications\Provider;
use App\Support\Communications\Results\DeliveryStatus;
use App\Support\Communications\Results\InboundMessage;
use App\Support\Communications\UnsubscribeToken;
use App\Support\Marketing\ConsentAction;
use App\Support\Marketing\ConsentRuleException;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ConsentWriter;
use Database\Seeders\SubscriptionListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class ConsentLedgerTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    private SubscriptionList $promotions;

    private SubscriptionList $newsletter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionListSeeder::class);
        $this->promotions = SubscriptionList::query()->where('key', 'promotions')->firstOrFail();
        $this->newsletter = SubscriptionList::query()->where('key', 'newsletter')->firstOrFail();
    }

    #[Test]
    public function grant_without_text_or_import_evidence_is_rejected(): void
    {
        $contact = Contact::factory()->create();
        $text = ConsentText::factory()->create();

        try {
            ConsentWriter::grant($contact, Channel::Email, $this->newsletter, ConsentSource::Form);
            $this->fail('Form grant without a consent text must be rejected.');
        } catch (ConsentRuleException $exception) {
            $this->assertSame('consent_text', $exception->errorKey);
        }

        $employee = Employee::factory()->withoutRoleGrant()->create();

        try {
            ConsentWriter::grant(
                $contact,
                Channel::Email,
                $this->newsletter,
                ConsentSource::Operator,
                null,
                null,
                $employee,
            );
            $this->fail('Operator grant without a note must be rejected.');
        } catch (ConsentRuleException $exception) {
            $this->assertSame('operator_note', $exception->errorKey);
        }

        try {
            ConsentWriter::grant(
                $contact,
                Channel::Email,
                $this->newsletter,
                ConsentSource::Import,
                $text->id,
                ['import_batch' => 'cutover'],
            );
            $this->fail('Import grant without an original source must be rejected.');
        } catch (ConsentRuleException $exception) {
            $this->assertSame('import_evidence', $exception->errorKey);
        }

        $this->assertSame(0, ConsentEvent::query()->count());
    }

    #[Test]
    public function operator_grant_with_a_note_does_not_require_consent_text(): void
    {
        $contact = Contact::factory()->create();
        $employee = Employee::factory()->withoutRoleGrant()->create();

        $event = ConsentWriter::grant(
            $contact,
            Channel::Email,
            $this->newsletter,
            ConsentSource::Operator,
            null,
            ['note' => 'Confirmed by phone'],
            $employee,
        );

        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertNull($event->consent_text_id);

        $activity = Activity::query()->where('description', 'contact.consent.granted')->firstOrFail();
        $this->assertSame('comms', $activity->log_name);
        $encoded = json_encode($activity->properties);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Confirmed by phone', $encoded);
        $this->assertSame($this->newsletter->id, (int) $activity->properties['subscription_list_id']);
    }

    #[Test]
    public function latest_event_wins_including_same_timestamp_tie_break(): void
    {
        $contact = Contact::factory()->create();
        $text = ConsentText::factory()->create();
        $at = now()->startOfSecond();

        ConsentWriter::grant(
            $contact,
            Channel::Email,
            $this->newsletter,
            ConsentSource::Form,
            $text->id,
            null,
            null,
            $at,
        );
        ConsentWriter::withdraw(
            $contact,
            Channel::Email,
            $this->newsletter,
            ConsentSource::PreferenceCenter,
            null,
            null,
            $at->copy()->addMinute(),
        );

        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));

        $tied = now()->startOfSecond();
        ConsentWriter::withdraw(
            $contact,
            Channel::Email,
            $this->promotions,
            ConsentSource::PreferenceCenter,
            null,
            null,
            $tied,
        );
        ConsentWriter::grant(
            $contact,
            Channel::Email,
            $this->promotions,
            ConsentSource::Form,
            $text->id,
            null,
            null,
            $tied,
        );

        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->promotions->id));

        $ids = ConsentState::grantedQuery(Channel::Email, $this->promotions->id)->pluck('contact_id')->all();
        $this->assertContains($contact->id, $ids);
        $this->assertNotContains(
            $contact->id,
            ConsentState::grantedQuery(Channel::Email, $this->newsletter->id)->pluck('contact_id')->all(),
        );
    }

    #[Test]
    public function one_click_post_withdraws_every_list_for_every_contact_on_the_address(): void
    {
        $text = ConsentText::factory()->create();
        $primary = Contact::factory()->create(['email' => 'shared@example.com']);
        $secondary = Contact::factory()->create(['email' => 'other@example.com']);
        ContactChannel::query()->create([
            'contact_id' => $secondary->id,
            'type' => ContactChannelType::Email,
            'value' => 'shared@example.com',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        foreach ([$primary, $secondary] as $contact) {
            ConsentWriter::grant($contact, Channel::Email, $this->newsletter, ConsentSource::Form, $text->id);
            ConsentWriter::grant($contact, Channel::Email, $this->promotions, ConsentSource::Form, $text->id);
        }

        $before = ConsentEvent::query()->count();
        $suppressions = ChannelSuppression::query()->count();
        $token = UnsubscribeToken::issue('shared@example.com');

        $this->getJson('/api/comms/unsubscribe/'.$token)->assertRedirect();
        $this->assertSame($before, ConsentEvent::query()->count());
        $this->assertSame($suppressions, ChannelSuppression::query()->count());
        $this->assertTrue(ConsentState::isGranted($primary->id, Channel::Email, $this->newsletter->id));

        $this->post('/api/comms/unsubscribe/'.$token)->assertOk();

        foreach ([$primary, $secondary] as $contact) {
            $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
            $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->promotions->id));
        }

        $this->assertSame(
            4,
            ConsentEvent::query()->where('source', ConsentSource::OneClick)->count(),
        );
    }

    #[Test]
    public function stop_withdraws_sms_lists_and_complaint_withdraws_email_lists(): void
    {
        $text = ConsentText::factory()->create();
        $contact = Contact::factory()->create(['email' => 'renter@example.com']);
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        ConsentWriter::grant($contact, Channel::Sms, $this->promotions, ConsentSource::Form, $text->id);
        ConsentWriter::grant($contact, Channel::Email, $this->newsletter, ConsentSource::Form, $text->id);
        ConsentWriter::grant($contact, Channel::Email, $this->promotions, ConsentSource::Form, $text->id);

        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Sms,
            'provider' => Provider::Twilio,
            'is_active' => true,
            'credentials' => ['token' => 'test'],
            'webhook_url_token' => 'consent-stop-token',
            'status' => CredentialStatus::Connected,
        ]);

        $result = InboundReceiptApplier::apply(
            Provider::Twilio,
            $account->id,
            new InboundMessage(
                'stop-1',
                'stop-1-event',
                Channel::Sms,
                '+15551234567',
                '+15550001111',
                null,
                'STOP',
                null,
                [],
                [],
                false,
                null,
                [],
            ),
        );
        $this->assertSame('message', $result['outcome']);
        $messageId = $result['message']->id;
        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Sms, $this->promotions->id));
        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertSame(
            0,
            ConsentEvent::query()
                ->where('source', ConsentSource::StopKeyword)
                ->where('subscription_list_id', $this->newsletter->id)
                ->count(),
        );

        $beforeBounce = ConsentEvent::query()->count();
        event(new ChannelDeliveryFailed(
            messageId: $messageId,
            channel: Channel::Email,
            address: 'renter@example.com',
            isPermanent: true,
            status: DeliveryStatus::Bounced,
        ));
        $this->assertSame($beforeBounce, ConsentEvent::query()->count());
        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));

        event(new ChannelDeliveryFailed(
            messageId: $messageId,
            channel: Channel::Email,
            address: 'renter@example.com',
            isPermanent: true,
            status: DeliveryStatus::Spam,
        ));
        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->promotions->id));
        $complaints = ConsentEvent::query()
            ->where('source', ConsentSource::Complaint)
            ->get(['contact_id', 'subscription_list_id']);
        $this->assertCount(2, $complaints);
    }

    #[Test]
    public function redaction_nulls_evidence_and_withdraws_every_grant(): void
    {
        $contact = Contact::factory()->create();
        $employee = Employee::factory()->withoutRoleGrant()->create();
        $text = ConsentText::factory()->create();

        $grant = ConsentWriter::grant(
            $contact,
            Channel::Email,
            $this->newsletter,
            ConsentSource::Operator,
            $text->id,
            ['note' => 'secret note', 'ip' => '203.0.113.5'],
            $employee,
        );
        ConsentWriter::grant($contact, Channel::Email, $this->promotions, ConsentSource::Form, $text->id);

        $this->artisan('contacts:redact', ['contact' => $contact->id])->assertSuccessful();

        $grant->refresh();
        $this->assertNull($grant->evidence);
        $this->assertSame(ConsentAction::Granted, $grant->action);
        $this->assertSame(ConsentSource::Operator, $grant->source);
        $this->assertSame($text->id, $grant->consent_text_id);

        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));
        $this->assertFalse(ConsentState::isGranted($contact->id, Channel::Email, $this->promotions->id));
        $this->assertSame(
            2,
            ConsentEvent::query()->where('source', ConsentSource::Redaction)->count(),
        );
    }

    #[Test]
    public function ledger_rejects_update_and_delete(): void
    {
        $contact = Contact::factory()->create();
        $text = ConsentText::factory()->create();
        $event = ConsentWriter::grant(
            $contact,
            Channel::Email,
            $this->newsletter,
            ConsentSource::Form,
            $text->id,
        );

        $this->assertImmutable(function () use ($event): void {
            $event->delete();
        });

        $this->assertImmutable(function () use ($event): void {
            DB::table('consent_events')->where('id', $event->id)->update(['action' => 'withdrawn']);
        });

        $this->assertImmutable(function () use ($text): void {
            DB::table('consent_texts')->where('id', $text->id)->update(['body' => 'changed']);
        }, 'consent_text_immutable');

        $this->assertSame(ConsentAction::Granted, $event->refresh()->action);
    }

    /**
     * A failed statement aborts the test transaction unless it runs in a savepoint.
     */
    private function assertImmutable(callable $write, string $marker = 'consent_event_immutable'): void
    {
        try {
            DB::transaction($write);
            $this->fail('Mutation must be rejected.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString($marker, $exception->getMessage());
        }
    }

    #[Test]
    public function automation_and_ai_cannot_grant(): void
    {
        $roots = [
            app_path('Ai'),
            app_path('Support/Automation'),
        ];

        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                $this->assertStringNotContainsString(
                    'ConsentWriter::grant',
                    $file->getContents(),
                    $file->getRelativePathname(),
                );
            }
        }
    }

    #[Test]
    public function granted_query_uses_the_list_index(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('EXPLAIN of ce_list_idx runs on Postgres.');
        }

        $contact = Contact::factory()->create();
        $occurred = now();
        $rows = [];

        for ($i = 0; $i < 10000; $i++) {
            $rows[] = [
                'contact_id' => $contact->id,
                'channel' => Channel::Email->value,
                'subscription_list_id' => $this->newsletter->id,
                'action' => $i === 9999 ? ConsentAction::Granted->value : ConsentAction::Withdrawn->value,
                'source' => ConsentSource::Form->value,
                'consent_text_id' => null,
                'evidence' => null,
                'causer_type' => null,
                'causer_id' => null,
                'occurred_at' => $occurred->copy()->subSeconds(10000 - $i),
                'created_at' => $occurred,
            ];

            if (count($rows) === 500) {
                DB::table('consent_events')->insert($rows);
                $rows = [];
            }
        }

        DB::statement('ANALYZE consent_events');
        DB::statement('SET enable_seqscan = off');

        try {
            $query = ConsentState::grantedQuery(Channel::Email, $this->newsletter->id);
            $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
        } finally {
            DB::statement('SET enable_seqscan = on');
        }

        $text = implode("\n", array_map(
            static fn (object $row): string => (string) ($row->{'QUERY PLAN'} ?? ''),
            $plan,
        ));

        $this->assertStringContainsString('ce_list_idx', $text, $text);
    }

    #[Test]
    public function operator_api_records_a_grant_and_rejects_a_missing_note(): void
    {
        $contact = Contact::factory()->create();
        $text = ConsentText::factory()->create();
        $viewer = $this->employeeWithPermission(Permission::ContactView);
        $manager = $this->employeeWithPermission(Permission::ConsentManage);

        Sanctum::actingAs($viewer);
        $lists = collect($this->getJson('/api/contacts/'.$contact->id.'/consents')
            ->assertOk()
            ->json('data.lists'));
        $this->assertTrue($lists->contains(fn (array $list): bool => $list['key'] === 'newsletter'));

        Sanctum::actingAs($manager);
        $this->postJson('/api/contacts/'.$contact->id.'/consents', [
            'channel' => 'email',
            'subscription_list_id' => $this->newsletter->id,
            'action' => 'granted',
        ])->assertStatus(422);

        $this->postJson('/api/contacts/'.$contact->id.'/consents', [
            'channel' => 'email',
            'subscription_list_id' => $this->newsletter->id,
            'action' => 'pending',
            'note' => 'no',
        ])->assertStatus(422);

        $this->postJson('/api/contacts/'.$contact->id.'/consents', [
            'channel' => 'email',
            'subscription_list_id' => $this->newsletter->id,
            'action' => 'granted',
            'consent_text_id' => $text->id,
            'note' => 'Asked on the phone',
        ])->assertCreated()
            ->assertJsonPath('data.action', 'granted')
            ->assertJsonPath('data.source', 'operator');

        $this->assertTrue(ConsentState::isGranted($contact->id, Channel::Email, $this->newsletter->id));

        $this->postJson('/api/consent-texts', [
            'key' => 'newsletter_signup',
            'locale' => 'en',
            'body' => 'I agree to the newsletter.',
        ])->assertCreated()
            ->assertJsonPath('data.version', 1);

        $this->postJson('/api/consent-texts', [
            'key' => 'newsletter_signup',
            'locale' => 'en',
            'body' => 'I agree to the newsletter. Version two.',
        ])->assertCreated()
            ->assertJsonPath('data.version', 2);

        Sanctum::actingAs($viewer);
        $this->postJson('/api/consent-texts', [
            'key' => 'newsletter_signup',
            'locale' => 'es',
            'body' => 'Acepto.',
        ])->assertForbidden();
    }
}
