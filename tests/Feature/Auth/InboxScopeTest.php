<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

/**
 * S17-04 — inbox threads scope inside the aggregate query itself (never a
 * post-filter), so cursor pagination never skips a page.
 */
class InboxScopeTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;
    use SeedsInboxThreads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
    }

    #[Test]
    public function threads_scoped_in_aggregate_query(): void
    {
        // Site-scoped accounts (unique per site) so each thread resolves to
        // exactly one site via SiteSenderIdentity — company-scope Brevo is
        // unique on (channel, provider) and cannot be seeded twice.
        $accountA = $this->seedSiteEmailAccount($this->siteA);
        $accountB = $this->seedSiteEmailAccount($this->siteB);

        $contactA = Contact::factory()->create();
        $contactB = Contact::factory()->create();

        $threadsA = [];
        for ($i = 0; $i < 3; $i++) {
            $threadsA[] = $this->makeInboxThread($contactA, [
                'subject' => "Site A thread {$i}",
                'last_message_at' => now()->subMinutes(10 - $i),
            ], [
                'communication_account_id' => $accountA->id,
            ]);
        }

        $threadB = $this->makeInboxThread($contactB, [
            'subject' => 'Site B thread',
            'last_message_at' => now(),
        ], [
            'communication_account_id' => $accountB->id,
        ]);

        Sanctum::actingAs($this->agent);

        $page1 = $this->getJson('/api/inbox/threads?per_page=2')->assertOk();
        $ids1 = collect($page1->json('data'))->pluck('id')->all();
        $this->assertNotContains($threadB->id, $ids1);

        $cursor = $page1->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $page2 = $this->getJson('/api/inbox/threads?per_page=2&cursor='.urlencode((string) $cursor))->assertOk();
        $ids2 = collect($page2->json('data'))->pluck('id')->all();
        $this->assertNotContains($threadB->id, $ids2);

        // No post-filter page-skip: exactly the 3 site-A threads split across
        // 2 pages, no overlap, and the excluded thread never occupies a slot.
        $this->assertEmpty(array_intersect($ids1, $ids2));
        $expected = collect($threadsA)->pluck('id')->sort()->values()->all();
        $actual = collect(array_merge($ids1, $ids2))->sort()->values()->all();
        $this->assertSame($expected, $actual);
        $this->assertNull($page2->json('meta.next_cursor'));

        // Company-wide owner sees all 4 threads across both sites.
        Sanctum::actingAs($this->owner);
        $ownerAll = $this->getJson('/api/inbox/threads?per_page=10')->assertOk();
        $this->assertCount(4, $ownerAll->json('data'));
    }

    private function seedSiteEmailAccount(Site $site): CommunicationAccount
    {
        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Site,
            'site_id' => $site->id,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'test-key-'.$site->id],
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $site->id,
            'channel' => Channel::Email,
            'account_id' => $account->id,
            'from_name' => 'Keevaris',
            'from_email' => 'desk-'.$site->id.'@example.com',
            'reply_to_email' => 'reply-'.$site->id.'@example.com',
        ]);

        return $account;
    }
}
