<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Employee;
use App\Models\WhatsappTemplate;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Support\Communications\WhatsAppTemplateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class WaTemplateTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = CommunicationAccount::query()->create([
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

        Sanctum::actingAs(Employee::factory()->manager()->create());
    }

    public function test_approved_readonly_clone_flow(): void
    {
        $approved = WhatsappTemplate::query()->create([
            'name' => 'payment_reminder',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Pay by {{1}}',
            'variables' => [['index' => 1, 'label' => 'date', 'sample' => 'Friday']],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
            'provider_template_id' => 'prov-approved',
            'submitted_at' => now()->subDay(),
            'decided_at' => now()->subDay(),
        ]);

        $put = $this->putJson("/api/whatsapp-templates/{$approved->id}", [
            'name' => 'payment_reminder',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Changed body {{1}}',
            'variables' => [['index' => 1, 'label' => 'date', 'sample' => 'Friday']],
        ]);
        $put->assertStatus(422);

        try {
            $approved->fill(['body' => 'Direct mutate {{1}}'])->save();
            $this->fail('Expected RuntimeException for approved immutability');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $clone = $this->postJson("/api/whatsapp-templates/{$approved->id}/clone");
        $clone->assertCreated();
        $this->assertSame('payment_reminder_v2', $clone->json('data.name'));
        $this->assertSame(WhatsappTemplate::STATUS_DRAFT, $clone->json('data.status'));
        $this->assertSame($approved->id, $clone->json('data.supersedes_id'));
        $this->assertArrayNotHasKey('lineage', $clone->json('data'));

        $show = $this->getJson('/api/whatsapp-templates/'.$clone->json('data.id'));
        $show->assertOk();
        $show->assertJsonCount(2, 'data.lineage');
        $show->assertJsonPath('data.lineage.0.id', $clone->json('data.id'));
        $show->assertJsonPath('data.lineage.0.name', 'payment_reminder_v2');
        $show->assertJsonPath('data.lineage.0.language', 'en');
        $show->assertJsonPath('data.lineage.0.status', WhatsappTemplate::STATUS_DRAFT);
        $show->assertJsonPath('data.lineage.0.decided_at', null);
        $show->assertJsonPath('data.lineage.1.id', $approved->id);
        $show->assertJsonPath('data.lineage.1.name', 'payment_reminder');
        $show->assertJsonPath('data.lineage.1.language', 'en');
        $show->assertJsonPath('data.lineage.1.status', WhatsappTemplate::STATUS_APPROVED);
        $this->assertNotNull($show->json('data.lineage.1.decided_at'));
    }

    public function test_clone_of_a_clone_walks_three_deep(): void
    {
        $approved = WhatsappTemplate::query()->create([
            'name' => 'payment_reminder',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Pay by {{1}}',
            'variables' => [['index' => 1, 'label' => 'date', 'sample' => 'Friday']],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
            'decided_at' => now()->subDay(),
        ]);

        $first = $this->postJson("/api/whatsapp-templates/{$approved->id}/clone");
        $first->assertCreated();
        $firstId = $first->json('data.id');

        $second = $this->postJson("/api/whatsapp-templates/{$firstId}/clone");
        $second->assertCreated();
        $this->assertSame('payment_reminder_v3', $second->json('data.name'));
        $this->assertSame($firstId, $second->json('data.supersedes_id'));

        $show = $this->getJson('/api/whatsapp-templates/'.$second->json('data.id'));
        $show->assertOk();
        $show->assertJsonPath('data.lineage.0.id', $second->json('data.id'));
        $show->assertJsonPath('data.lineage.1.id', $firstId);
        $show->assertJsonPath('data.lineage.2.id', $approved->id);
        $show->assertJsonCount(3, 'data.lineage');
    }

    public function test_root_template_lineage_is_itself(): void
    {
        $draft = WhatsappTemplate::query()->create([
            'name' => 'welcome',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Hello',
            'variables' => [],
            'status' => WhatsappTemplate::STATUS_DRAFT,
            'communication_account_id' => $this->account->id,
        ]);

        $this->assertNull($draft->supersedes_id);

        $show = $this->getJson("/api/whatsapp-templates/{$draft->id}");
        $show->assertOk();
        $show->assertJsonPath('data.supersedes_id', null);
        $show->assertJsonCount(1, 'data.lineage');
        $show->assertJsonPath('data.lineage.0.id', $draft->id);
        $show->assertJsonPath('data.lineage.0.decided_at', null);

        $index = $this->getJson('/api/whatsapp-templates');
        $index->assertOk();
        $row = collect($index->json('data'))->firstWhere('id', $draft->id);
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('lineage', $row);
        $this->assertNull($row['supersedes_id']);
    }

    public function test_lineage_walk_is_capped_at_twenty(): void
    {
        $previousId = null;
        $newest = null;

        for ($i = 1; $i <= 21; $i++) {
            $newest = WhatsappTemplate::query()->create([
                'name' => 'chain_v'.$i,
                'language' => 'en',
                'category' => 'utility',
                'body' => 'Hello',
                'variables' => [],
                'status' => WhatsappTemplate::STATUS_DRAFT,
                'communication_account_id' => $this->account->id,
                'supersedes_id' => $previousId,
            ]);
            $previousId = $newest->id;
        }

        $show = $this->getJson("/api/whatsapp-templates/{$newest->id}");
        $show->assertOk();
        $show->assertJsonCount(20, 'data.lineage');
        $show->assertJsonPath('data.lineage.0.name', 'chain_v21');
        $show->assertJsonPath('data.lineage.19.name', 'chain_v2');
    }

    public function test_clone_and_archive_frees_identity(): void
    {
        $approved = WhatsappTemplate::query()->create([
            'name' => 'payment_reminder',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Pay by {{1}}',
            'variables' => [['index' => 1, 'label' => 'date', 'sample' => 'Friday']],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
        ]);

        $clone = $this->postJson("/api/whatsapp-templates/{$approved->id}/clone");
        $clone->assertCreated();
        $this->assertSame('payment_reminder_v2', $clone->json('data.name'));
        $this->assertSame(WhatsappTemplate::STATUS_DRAFT, $clone->json('data.status'));

        $this->postJson("/api/whatsapp-templates/{$approved->id}/archive")->assertOk();
        $this->assertSame(
            WhatsappTemplate::STATUS_ARCHIVED,
            $approved->fresh()->status
        );

        // Identity freed: can create same name+language again.
        $recreate = $this->postJson('/api/whatsapp-templates', [
            'name' => 'payment_reminder',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Pay by {{1}}',
            'variables' => [['index' => 1, 'label' => 'date', 'sample' => 'Friday']],
        ]);
        $recreate->assertCreated();
    }

    public function test_placeholder_validation_matrix(): void
    {
        // Bad slug
        try {
            WhatsAppTemplateValidator::validate([
                'name' => 'Bad Name!',
                'language' => 'en',
                'category' => 'utility',
                'body' => 'Hello',
                'variables' => [],
            ]);
            $this->fail('Expected ValidationException for bad slug');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        // Non-sequential / gap
        try {
            WhatsAppTemplateValidator::validate([
                'name' => 'gap_test',
                'language' => 'en',
                'category' => 'utility',
                'body' => 'Hello {{1}} and {{3}}',
                'variables' => [
                    ['index' => 1, 'label' => 'a', 'sample' => 'A'],
                    ['index' => 3, 'label' => 'c', 'sample' => 'C'],
                ],
            ]);
            $this->fail('Expected ValidationException for gap');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        // Missing variable row
        try {
            WhatsAppTemplateValidator::validate([
                'name' => 'missing_var',
                'language' => 'en',
                'category' => 'utility',
                'body' => 'Hello {{1}}',
                'variables' => [],
            ]);
            $this->fail('Expected ValidationException for missing variable');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('variables', $e->errors());
        }

        // Missing samples at submit
        try {
            WhatsAppTemplateValidator::validate([
                'name' => 'no_sample',
                'language' => 'en',
                'category' => 'utility',
                'body' => 'Hello {{1}}',
                'variables' => [['index' => 1, 'label' => 'name', 'sample' => null]],
            ], requireSamples: true);
            $this->fail('Expected ValidationException for missing sample');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        // Happy path
        $ok = WhatsAppTemplateValidator::validate([
            'name' => 'ok_template',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Hello {{1}} on {{2}}',
            'variables' => [
                ['index' => 1, 'label' => 'name', 'sample' => 'Ada'],
                ['index' => 2, 'label' => 'day', 'sample' => 'Monday'],
            ],
        ], requireSamples: true);
        $this->assertSame('ok_template', $ok['name']);
        $this->assertCount(2, $ok['variables']);
    }
}
