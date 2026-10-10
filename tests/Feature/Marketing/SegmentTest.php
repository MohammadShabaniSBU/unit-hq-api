<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\ContactChannelType;
use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Segment;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Auth\Permission;
use App\Support\Auth\RoleScope;
use App\Support\Communications\Channel;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentWriter;
use Database\Seeders\SubscriptionListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class SegmentTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    private SubscriptionList $newsletter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionListSeeder::class);
        $this->newsletter = SubscriptionList::query()->where('key', 'newsletter')->firstOrFail();
    }

    #[Test]
    public function site_id_uses_the_business_site_priority(): void
    {
        $madrid = Site::factory()->create();
        $other = Site::factory()->create();
        $contact = Contact::factory()->create();

        Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $madrid->id,
            'status' => DealStatus::New,
        ]);

        $unit = Unit::factory()->create(['site_id' => $other->id]);
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'status' => ContractStatus::Active,
        ]);
        UnitOccupancy::factory()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
        ]);

        $this->assertSame([$contact->id], $this->ids('site_id', 'eq', $madrid->id));
        $this->assertSame([], $this->ids('site_id', 'eq', $other->id));
    }

    #[Test]
    public function has_active_contract_matches_active_and_notice_given_only(): void
    {
        $active = $this->contactWithContract(ContractStatus::Active);
        $notice = $this->contactWithContract(ContractStatus::NoticeGiven);
        $ended = $this->contactWithContract(ContractStatus::Ended);

        $this->assertEqualsCanonicalizing(
            [$active->id, $notice->id],
            $this->ids('has_active_contract', 'eq', true),
        );
        $this->assertContains($ended->id, $this->ids('has_active_contract', 'eq', false));
        $this->assertNotContains($active->id, $this->ids('has_active_contract', 'eq', false));
    }

    #[Test]
    public function contract_unit_class_reads_the_open_occupancy(): void
    {
        $class = UnitClass::factory()->create();
        $other = UnitClass::factory()->create();
        $contact = Contact::factory()->create();
        $unit = Unit::factory()->create(['unit_class_id' => $class->id]);
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'status' => ContractStatus::Active,
        ]);
        UnitOccupancy::factory()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
        ]);

        $this->assertSame([$contact->id], $this->ids('contract_unit_class_id', 'eq', $class->id));
        $this->assertSame([], $this->ids('contract_unit_class_id', 'eq', $other->id));
    }

    #[Test]
    public function contract_dates_match_start_and_end(): void
    {
        $contact = Contact::factory()->create();
        Contract::factory()->create([
            'contact_id' => $contact->id,
            'status' => ContractStatus::Ended,
            'start_date' => '2024-01-10',
            'end_date' => '2025-03-01',
        ]);
        Contact::factory()->create();

        $this->assertSame([$contact->id], $this->ids('contract_started_at', 'after', '2024-01-01'));
        $this->assertSame([], $this->ids('contract_started_at', 'after', '2024-06-01'));
        $this->assertSame(
            [$contact->id],
            $this->ids('contract_ended_at', 'between', ['2025-01-01', '2025-12-31']),
        );
    }

    #[Test]
    public function is_delinquent_matches_an_open_case_only(): void
    {
        $open = $this->contactWithContract(ContractStatus::Active);
        Delinquency::factory()->create(['contract_id' => $open->contracts()->firstOrFail()->id]);

        $cured = $this->contactWithContract(ContractStatus::Active);
        Delinquency::factory()->cured()->create(['contract_id' => $cured->contracts()->firstOrFail()->id]);

        $this->assertSame([$open->id], $this->ids('is_delinquent', 'eq', true));
        $this->assertContains($cured->id, $this->ids('is_delinquent', 'eq', false));
    }

    #[Test]
    public function deal_status_buckets_open_won_and_lost(): void
    {
        $open = $this->contactWithDeal(DealStatus::Qualified);
        $won = $this->contactWithDeal(DealStatus::ClosedWon);
        $lost = $this->contactWithDeal(DealStatus::ClosedLost);

        $this->assertSame([$open->id], $this->ids('deal_status', 'eq', 'open'));
        $this->assertSame([$won->id], $this->ids('deal_status', 'eq', 'won'));
        $this->assertSame([$lost->id], $this->ids('deal_status', 'eq', 'lost'));
    }

    #[Test]
    public function deal_lost_at_uses_the_closed_lost_timestamp(): void
    {
        $lost = $this->contactWithDeal(DealStatus::ClosedLost);
        $deal = $lost->deals()->firstOrFail();
        DB::table('deals')->where('id', $deal->id)->update(['updated_at' => '2025-06-01 12:00:00']);
        $this->contactWithDeal(DealStatus::New);

        $this->assertSame([$lost->id], $this->ids('deal_lost_at', 'after', '2025-01-01'));
        $this->assertSame([], $this->ids('deal_lost_at', 'after', '2025-12-01'));
    }

    #[Test]
    public function deal_desired_unit_class_matches_the_deal(): void
    {
        $class = UnitClass::factory()->create();
        $other = UnitClass::factory()->create();
        $contact = Contact::factory()->create();
        Deal::factory()->create([
            'contact_id' => $contact->id,
            'desired_unit_class_id' => $class->id,
            'status' => DealStatus::Qualified,
        ]);

        $this->assertSame([$contact->id], $this->ids('deal_desired_unit_class_id', 'eq', $class->id));
        $this->assertSame([], $this->ids('deal_desired_unit_class_id', 'eq', $other->id));
    }

    #[Test]
    public function has_channel_requires_a_usable_address(): void
    {
        $mail = Contact::factory()->create(['email' => 'ada@example.test']);
        $blocked = Contact::factory()->create(['email' => 'blocked@example.test']);
        SuppressionWriter::write(
            Channel::Email,
            'blocked@example.test',
            SuppressionScope::All,
            SuppressionReason::Manual,
        );
        $sms = Contact::factory()->create(['email' => null]);
        ContactChannel::query()->create([
            'contact_id' => $sms->id,
            'type' => ContactChannelType::Sms,
            'value' => '+15551212000',
            'is_primary' => true,
            'opted_in' => false,
        ]);

        $this->assertSame([$mail->id], $this->ids('has_channel', 'eq', 'email'));
        $this->assertSame([$sms->id], $this->ids('has_channel', 'eq', 'sms'));
        $this->assertNotContains($blocked->id, $this->ids('has_channel', 'eq', 'email'));
    }

    #[Test]
    public function consent_matches_the_latest_granted_event(): void
    {
        $text = ConsentText::factory()->create();
        $granted = Contact::factory()->create();
        $withdrawn = Contact::factory()->create();
        $evidence = ['import_batch' => 'batch-1', 'original_source' => 'paper form'];

        ConsentWriter::grant($granted, Channel::Email, $this->newsletter, ConsentSource::Import, $text->id, $evidence);
        ConsentWriter::grant($withdrawn, Channel::Email, $this->newsletter, ConsentSource::Import, $text->id, $evidence);
        ConsentWriter::withdraw($withdrawn, Channel::Email, $this->newsletter, ConsentSource::Import, $evidence);

        $value = ['list_id' => $this->newsletter->id, 'channel' => 'email'];

        $this->assertSame([$granted->id], $this->ids('consent', 'eq', $value));
    }

    #[Test]
    public function a_removed_field_is_segment_invalid_on_use(): void
    {
        $manager = $this->employeeWithPermissions([Permission::MarketingManage, Permission::MarketingView, Permission::ContactView]);
        Sanctum::actingAs($manager);

        $created = $this->postJson('/api/segments', [
            'name' => 'Prospects',
            'is_shared' => true,
            'filter' => [
                'op' => 'and',
                'conditions' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'prospect'],
                ],
            ],
        ])->assertCreated();

        $id = (int) $created->json('data.id');
        DB::table('segments')->where('id', $id)->update([
            'filter' => json_encode([
                'op' => 'and',
                'conditions' => [
                    ['field' => 'not_a_field', 'op' => 'eq', 'value' => 'x'],
                ],
            ]),
        ]);

        $this->postJson('/api/segments/preview', ['segment_id' => $id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'segment.invalid')
            ->assertJsonPath('data.field', 'not_a_field');

        $this->getJson('/api/segments/'.$id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'segment.invalid')
            ->assertJsonPath('data.field', 'not_a_field');

        $this->getJson('/api/segments')
            ->assertOk()
            ->assertJsonPath('data.0.invalid_field', 'not_a_field')
            ->assertJsonPath('data.0.count', null);
    }

    #[Test]
    public function preview_count_follows_contact_view_site_scope(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $atA = Contact::factory()->create();
        $atB = Contact::factory()->create();
        Deal::factory()->create(['contact_id' => $atA->id, 'site_id' => $siteA->id, 'status' => DealStatus::New]);
        Deal::factory()->create(['contact_id' => $atB->id, 'site_id' => $siteB->id, 'status' => DealStatus::New]);

        $filter = [
            'op' => 'and',
            'conditions' => [
                ['field' => 'status', 'op' => 'eq', 'value' => 'prospect'],
            ],
        ];

        $company = $this->employeeWithPermissions([Permission::MarketingView, Permission::ContactView]);
        Sanctum::actingAs($company);
        $this->postJson('/api/segments/preview', ['filter' => $filter])
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $site = $this->employeeWithPermissions([Permission::MarketingView, Permission::ContactView], $siteA);
        Sanctum::actingAs($site);
        $this->postJson('/api/segments/preview', ['filter' => $filter])
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.sample.0.id', $atA->id);
    }

    #[Test]
    public function personal_views_stay_with_their_owner(): void
    {
        $owner = $this->employeeWithPermission(Permission::ContactView);
        $other = $this->employeeWithPermission(Permission::ContactView);
        Sanctum::actingAs($owner);

        $created = $this->postJson('/api/segments', [
            'name' => 'Mine',
            'is_shared' => false,
            'filter' => [
                'op' => 'and',
                'conditions' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'lead'],
                ],
            ],
        ])->assertCreated();

        $id = (int) $created->json('data.id');
        $this->assertFalse($created->json('data.is_shared'));

        $this->postJson('/api/segments', [
            'name' => 'Shared',
            'is_shared' => true,
            'filter' => [
                'op' => 'and',
                'conditions' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'lead'],
                ],
            ],
        ])->assertForbidden();

        Sanctum::actingAs($other);
        $this->getJson('/api/segments/'.$id)->assertForbidden();
        $this->getJson('/api/segments?scope=personal')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($owner);
        $this->postJson('/api/segments/'.$id.'/archive')->assertOk();
        $this->assertNotNull(Segment::query()->findOrFail($id)->archived_at);
    }

    #[Test]
    public function index_orders_by_created_at_and_paginates_when_asked(): void
    {
        $viewer = $this->employeeWithPermission(Permission::MarketingView);
        Sanctum::actingAs($viewer);

        $newest = Segment::factory()->create(['name' => 'Newest']);
        $newest->forceFill(['created_at' => now()])->save();
        $older = Segment::factory()->create(['name' => 'Older']);
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $this->getJson('/api/segments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonMissingPath('meta');

        $this->getJson('/api/segments?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);
    }

    #[Test]
    public function preview_of_fifty_thousand_contacts_stays_under_one_second_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('50k preview timing runs on Postgres.');
        }

        $now = now()->toDateTimeString();
        for ($batch = 0; $batch < 50; $batch++) {
            $rows = [];
            for ($i = 0; $i < 1000; $i++) {
                $n = ($batch * 1000) + $i;
                $rows[] = [
                    'first_name' => 'P',
                    'last_name' => (string) $n,
                    'email' => "preview{$n}@example.test",
                    'status' => 'prospect',
                    'contact_status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('contacts')->insert($rows);
        }

        DB::statement('ANALYZE contacts');

        $viewer = $this->employeeWithPermissions([Permission::MarketingView, Permission::ContactView]);
        Sanctum::actingAs($viewer);

        $started = microtime(true);
        $response = $this->postJson('/api/segments/preview', [
            'filter' => [
                'op' => 'and',
                'conditions' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'prospect'],
                ],
            ],
        ]);
        $elapsed = microtime(true) - $started;

        $response->assertOk()->assertJsonPath('data.count', 50000);

        $started = microtime(true);
        $this->postJson('/api/segments/preview', [
            'filter' => [
                'op' => 'and',
                'conditions' => [
                    ['field' => 'has_active_contract', 'op' => 'eq', 'value' => false],
                ],
            ],
        ])->assertOk()->assertJsonPath('data.count', 50000);
        $relationElapsed = microtime(true) - $started;

        $explained = Contact::query()->where('contacts.status', 'prospect');
        $plan = implode("\n", array_map(
            static fn (object $row): string => (string) ($row->{'QUERY PLAN'} ?? ''),
            DB::select('EXPLAIN '.$explained->toSql(), $explained->getBindings()),
        ));

        $this->assertLessThan(1.0, $elapsed, $plan);
        $this->assertLessThan(1.0, $relationElapsed, $plan);
    }

    private function contactWithContract(ContractStatus $status): Contact
    {
        $contact = Contact::factory()->create();
        Contract::factory()->create([
            'contact_id' => $contact->id,
            'status' => $status,
        ]);

        return $contact;
    }

    private function contactWithDeal(DealStatus $status): Contact
    {
        $contact = Contact::factory()->create();
        Deal::factory()->create([
            'contact_id' => $contact->id,
            'status' => $status,
        ]);

        return $contact;
    }

    /** @return list<int> */
    private function ids(string $field, string $op, mixed $value): array
    {
        $viewer = $this->employeeWithPermissions([Permission::MarketingView, Permission::ContactView]);
        Sanctum::actingAs($viewer);

        $response = $this->postJson('/api/segments/preview', [
            'filter' => [
                'op' => 'and',
                'conditions' => [
                    ['field' => $field, 'op' => $op, 'value' => $value],
                ],
            ],
        ])->assertOk();

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $response->json('data.sample'),
        );
    }

    /** @param  list<Permission>  $permissions */
    private function employeeWithPermissions(array $permissions, ?Site $site = null): Employee
    {
        $role = Role::query()->create([
            'key' => 'test-segments-'.uniqid(),
            'label' => 'Test segments',
            'scope_level' => $site === null ? RoleScope::Company : RoleScope::Site,
            'is_system' => false,
        ]);

        foreach ($permissions as $permission) {
            RolePermission::query()->create([
                'role_id' => $role->id,
                'permission' => $permission->value,
            ]);
        }

        $employee = Employee::factory()->withoutRoleGrant()->create();
        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'site_id' => $site?->id,
        ]);

        return $employee;
    }
}
