<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Enums\ContactChannelType;
use App\Models\ConsentEvent;
use App\Models\ConsentText;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\Communications\Channel;
use App\Support\Marketing\ConsentSource;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ConsentWriter;
use Database\Seeders\SubscriptionListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class SubscriptionListTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    private SubscriptionList $promotions;

    private SubscriptionList $newsletter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionListSeeder::class);
        $this->seed(SubscriptionListSeeder::class);
        $this->promotions = SubscriptionList::query()->where('key', 'promotions')->firstOrFail();
        $this->newsletter = SubscriptionList::query()->where('key', 'newsletter')->firstOrFail();
    }

    #[Test]
    public function seeded_system_lists_exist_and_the_demo_stage_calls_the_seeder(): void
    {
        $this->assertTrue($this->promotions->is_system);
        $this->assertSame(['email', 'sms'], $this->promotions->channels);
        $this->assertSame('newsletter', $this->newsletter->kind->value);
        $this->assertSame(['email'], $this->newsletter->channels);
        $advertising = SubscriptionList::query()->where('key', 'advertising')->firstOrFail();
        $this->assertTrue($advertising->is_system);
        $this->assertFalse($advertising->is_public);
        $this->assertFalse($advertising->requires_double_opt_in);
        $this->assertSame('advertising', $advertising->kind->value);
        $this->assertSame(['ads'], $advertising->channels);
        $this->assertSame(1, SubscriptionList::query()->where('key', 'promotions')->count());
        $this->assertSame(1, SubscriptionList::query()->where('key', 'newsletter')->count());
        $this->assertSame(1, SubscriptionList::query()->where('key', 'advertising')->count());

        $stage = (string) file_get_contents(base_path('database/seeders/Demo/StageSeeder.php'));
        $this->assertStringContainsString('SubscriptionListSeeder::class', $stage);
    }

    #[Test]
    public function member_count_matches_granted_query_and_withdrawn_contacts_drop_out(): void
    {
        $kept = Contact::factory()->create();
        $dropped = Contact::factory()->create();
        $text = ConsentText::factory()->create();

        ConsentWriter::grant($kept, Channel::Email, $this->newsletter, ConsentSource::Import, $text->id, [
            'import_batch' => 'batch-1',
            'original_source' => 'paper form',
        ]);
        ConsentWriter::grant($dropped, Channel::Email, $this->newsletter, ConsentSource::Import, $text->id, [
            'import_batch' => 'batch-1',
            'original_source' => 'paper form',
        ]);

        $viewer = $this->employeeWithPermission(Permission::MarketingView);
        Sanctum::actingAs($viewer);

        $this->assertSame(
            (int) ConsentState::grantedQuery(Channel::Email, $this->newsletter->id)->count(),
            $this->memberCount('newsletter', 'email'),
        );
        $this->assertSame(2, $this->memberCount('newsletter', 'email'));

        ConsentWriter::withdraw($dropped, Channel::Email, $this->newsletter, ConsentSource::Operator, null, $viewer);

        $this->assertSame(1, (int) ConsentState::grantedQuery(Channel::Email, $this->newsletter->id)->count());
        $this->assertSame(1, $this->memberCount('newsletter', 'email'));

        $members = $this->getJson('/api/subscription-lists/'.$this->newsletter->id.'/members')
            ->assertOk()
            ->json('data');

        $ids = array_column($members, 'id');
        $this->assertSame([$kept->id], $ids);
        $this->assertNotContains($dropped->id, $ids);
    }

    #[Test]
    public function site_scoped_employee_sees_only_members_visible_to_them(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $text = ConsentText::factory()->create();

        $visible = Contact::factory()->create();
        $hidden = Contact::factory()->create();
        $visible->sites()->attach($siteA->id);
        $hidden->sites()->attach($siteB->id);

        ConsentWriter::grant($visible, Channel::Email, $this->newsletter, ConsentSource::Import, $text->id, [
            'import_batch' => 'batch-1',
            'original_source' => 'desk',
        ]);
        ConsentWriter::grant($hidden, Channel::Email, $this->newsletter, ConsentSource::Import, $text->id, [
            'import_batch' => 'batch-1',
            'original_source' => 'desk',
        ]);

        $employee = $this->employeeWithSiteScopedPermission(Permission::MarketingView, $siteA);
        Sanctum::actingAs($employee);

        $ids = $this->getJson('/api/subscription-lists/'.$this->newsletter->id.'/members')
            ->assertOk()
            ->json('data');

        $this->assertSame([$visible->id], array_column($ids, 'id'));
        $this->assertSame(2, $this->memberCount('newsletter', 'email'));
    }

    #[Test]
    public function system_lists_cannot_be_archived_and_keys_cannot_change(): void
    {
        $manager = $this->employeeWithPermission(Permission::MarketingManage);
        Sanctum::actingAs($manager);

        $this->postJson('/api/subscription-lists/'.$this->newsletter->id.'/archive')
            ->assertStatus(422);

        $this->putJson('/api/subscription-lists/'.$this->newsletter->id, [
            'key' => 'renamed_newsletter',
        ])->assertStatus(422);

        $this->assertNull($this->newsletter->fresh()?->archived_at);
        $this->assertSame('newsletter', $this->newsletter->fresh()?->key);

        $advertising = SubscriptionList::query()->where('key', 'advertising')->firstOrFail();
        $this->postJson('/api/subscription-lists/'.$advertising->id.'/archive')
            ->assertStatus(422);
        $this->putJson('/api/subscription-lists/'.$advertising->id, [
            'channels' => ['email'],
        ])->assertStatus(422);
        $this->assertSame(['ads'], $advertising->fresh()?->channels);

        $created = $this->postJson('/api/subscription-lists', [
            'key' => 'madrid_centro_offers',
            'labels' => [
                'en' => 'Madrid Centro offers',
                'es' => 'Ofertas Madrid Centro',
                'fr' => 'Offres Madrid Centro',
            ],
            'kind' => 'promotions',
            'channels' => ['email'],
            'requires_double_opt_in' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.key', 'madrid_centro_offers');

        $id = (int) $created->json('data.id');

        $this->putJson('/api/subscription-lists/'.$id, [
            'labels' => [
                'en' => 'Madrid Centro',
                'es' => 'Madrid Centro',
                'fr' => 'Madrid Centro',
            ],
        ])->assertOk()
            ->assertJsonPath('data.key', 'madrid_centro_offers');

        $this->postJson('/api/subscription-lists/'.$id.'/archive')->assertOk();
        $this->assertNotNull(SubscriptionList::query()->find($id)?->archived_at);

        Sanctum::actingAs($this->employeeWithPermission(Permission::MarketingView));
        $keys = array_column(
            $this->getJson('/api/subscription-lists')->assertOk()->json('data'),
            'key',
        );
        $this->assertNotContains('madrid_centro_offers', $keys);
    }

    #[Test]
    public function import_dry_run_writes_nothing_then_confirm_grants_and_rejects_rows_without_a_source(): void
    {
        $existing = Contact::factory()->create(['email' => 'ada@example.com']);
        ContactChannel::query()->create([
            'contact_id' => $existing->id,
            'type' => ContactChannelType::Email,
            'value' => 'ada@example.com',
            'is_primary' => true,
            'opted_in' => false,
        ]);

        $text = ConsentText::factory()->create();
        $manager = $this->employeeWithPermission(Permission::MarketingManage);
        Sanctum::actingAs($manager);

        $csv = implode("\n", [
            'email,phone,name,consent_date,original_source',
            'ada@example.com,,Ada Lovelace,2024-03-01,paper form',
            'new@example.com,,New Person,2024-03-02,',
            'other@example.com,,Other Person,2024-03-03,web form',
        ])."\n";

        $beforeContacts = Contact::query()->count();
        $beforeEvents = ConsentEvent::query()->count();

        $dry = $this->post('/api/subscription-lists/'.$this->newsletter->id.'/import', [
            'file' => UploadedFile::fake()->createWithContent('members.csv', $csv),
            'confirm' => '0',
            'create_missing' => '0',
        ])->assertOk();

        $dry->assertJsonPath('data.dry_run', true);
        $dry->assertJsonPath('data.matched', 1);
        $dry->assertJsonPath('data.created', 0);
        $dry->assertJsonPath('data.rejected', 2);
        $this->assertSame('original_source', $dry->json('data.rows.1.reason'));
        $this->assertSame('unmatched', $dry->json('data.rows.2.reason'));
        $this->assertSame($beforeContacts, Contact::query()->count());
        $this->assertSame($beforeEvents, ConsentEvent::query()->count());

        $preview = $this->post('/api/subscription-lists/'.$this->newsletter->id.'/import', [
            'file' => UploadedFile::fake()->createWithContent('members.csv', $csv),
            'confirm' => '0',
            'create_missing' => '1',
        ])->assertOk();

        $preview->assertJsonPath('data.dry_run', true);
        $preview->assertJsonPath('data.matched', 1);
        $preview->assertJsonPath('data.created', 1);
        $preview->assertJsonPath('data.rejected', 1);
        $this->assertSame($beforeContacts, Contact::query()->count());
        $this->assertSame($beforeEvents, ConsentEvent::query()->count());

        $this->post('/api/subscription-lists/'.$this->newsletter->id.'/import', [
            'file' => UploadedFile::fake()->createWithContent('members.csv', $csv),
            'confirm' => '1',
            'create_missing' => '1',
        ])->assertStatus(422);

        $this->assertSame($beforeEvents, ConsentEvent::query()->count());

        $confirmed = $this->post('/api/subscription-lists/'.$this->newsletter->id.'/import', [
            'file' => UploadedFile::fake()->createWithContent('members.csv', $csv),
            'confirm' => '1',
            'create_missing' => '1',
            'consent_text_id' => $text->id,
        ])->assertOk();

        $confirmed->assertJsonPath('data.dry_run', false);
        $confirmed->assertJsonPath('data.matched', 1);
        $confirmed->assertJsonPath('data.created', 1);
        $confirmed->assertJsonPath('data.rejected', 1);
        $this->assertSame('original_source', $confirmed->json('data.rows.1.reason'));

        $this->assertTrue(ConsentState::isGranted($existing->id, Channel::Email, $this->newsletter->id));

        $created = Contact::query()->where('email', 'other@example.com')->first();
        $this->assertNotNull($created);
        $this->assertTrue(ConsentState::isGranted((int) $created->id, Channel::Email, $this->newsletter->id));
        $this->assertNull(Contact::query()->where('email', 'new@example.com')->first());

        $event = ConsentEvent::query()
            ->where('contact_id', $created->id)
            ->where('source', ConsentSource::Import->value)
            ->first();
        $this->assertSame('web form', $event?->evidence['original_source'] ?? null);
        $this->assertNotSame('', (string) ($event?->evidence['import_batch'] ?? ''));
    }

    #[Test]
    public function marketing_routes_reject_employees_without_the_permission(): void
    {
        Sanctum::actingAs($this->employeeWithoutPermissions());

        $this->getJson('/api/subscription-lists')->assertForbidden();
        $this->postJson('/api/subscription-lists', [])->assertForbidden();
    }

    private function memberCount(string $key, string $channel): int
    {
        $lists = $this->getJson('/api/subscription-lists')->assertOk()->json('data');
        $list = collect($lists)->firstWhere('key', $key);

        return (int) ($list['member_counts'][$channel] ?? -1);
    }
}
