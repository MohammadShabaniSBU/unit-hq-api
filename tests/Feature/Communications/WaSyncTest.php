<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\WhatsappTemplate;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_without_webhooks(): void
    {
        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Whatsapp,
            'provider' => Provider::Sinch,
            'is_active' => true,
            'credentials' => [
                'project_id' => 'proj-test',
                'key_id' => 'key-id',
                'key_secret' => 'key-secret',
                'app_id' => 'app-test',
                'region' => 'us',
            ],
            'status' => CredentialStatus::Connected,
        ]);

        $toApprove = WhatsappTemplate::query()->create([
            'name' => 'sync_approve',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Hello {{1}}',
            'variables' => [['index' => 1, 'label' => 'name', 'sample' => 'A']],
            'status' => WhatsappTemplate::STATUS_SUBMITTED,
            'provider_template_id' => 'prov-approve',
            'communication_account_id' => $account->id,
            'submitted_at' => now(),
        ]);

        $toReject = WhatsappTemplate::query()->create([
            'name' => 'sync_reject',
            'language' => 'en',
            'category' => 'marketing',
            'body' => 'Offer {{1}}',
            'variables' => [['index' => 1, 'label' => 'deal', 'sample' => 'B']],
            'status' => WhatsappTemplate::STATUS_SUBMITTED,
            'provider_template_id' => 'prov-reject',
            'communication_account_id' => $account->id,
            'submitted_at' => now(),
        ]);

        $toRevoke = WhatsappTemplate::query()->create([
            'name' => 'sync_revoke',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Notice {{1}}',
            'variables' => [['index' => 1, 'label' => 'x', 'sample' => 'C']],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'provider_template_id' => 'prov-revoke',
            'communication_account_id' => $account->id,
            'submitted_at' => now()->subDays(10),
            'decided_at' => now()->subDays(9),
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            // List (may include ?pageSize=…)
            if (str_contains($url, '/whatsapp/templates')
                && ! preg_match('#/whatsapp/templates/[^/?]+#', $url)) {
                return Http::response([
                    'templates' => [
                        [
                            'id' => 'prov-approve',
                            'name' => 'sync_approve',
                            'language' => 'en',
                            'status' => 'APPROVED',
                        ],
                        [
                            'id' => 'prov-reject',
                            'name' => 'sync_reject',
                            'language' => 'en',
                            'status' => 'REJECTED',
                            'rejected_reason' => 'CATEGORY_MISMATCH: cryptic Meta reason XYZ',
                        ],
                        [
                            'id' => 'prov-revoke',
                            'name' => 'sync_revoke',
                            'language' => 'en',
                            'status' => 'DISABLED',
                        ],
                    ],
                ], 200);
            }

            // Individual fetch fallback
            if (preg_match('#/whatsapp/templates/([^/?]+)#', $url, $m)) {
                return Http::response([
                    'id' => $m[1],
                    'status' => 'PENDING',
                ], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        Artisan::call('whatsapp:sync-templates');

        $this->assertSame(WhatsappTemplate::STATUS_APPROVED, $toApprove->fresh()->status);
        $this->assertSame(WhatsappTemplate::STATUS_REJECTED, $toReject->fresh()->status);
        $this->assertSame(
            'CATEGORY_MISMATCH: cryptic Meta reason XYZ',
            $toReject->fresh()->rejection_reason
        );
        $this->assertSame(WhatsappTemplate::STATUS_REVOKED, $toRevoke->fresh()->status);
    }
}
