<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Communications\Channel;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\SuppressionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class ComposerSuppressionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;
    use SeedsInboxThreads;

    public function test_display_equals_enforcement(): void
    {
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create(['email' => 'suppressed@example.com']);
        $this->givePrimaryEmail($contact, 'suppressed@example.com');

        $thread = $this->makeInboxThread($contact, [
            'subject' => 'Help',
            'channel' => Channel::Email,
        ]);

        // Marketing-only: context surfaces it; transactional reply still sends.
        SuppressionWriter::write(
            Channel::Email,
            'suppressed@example.com',
            SuppressionScope::Marketing,
            SuppressionReason::Unsubscribed,
        );

        $ctxMarketing = $this->getJson('/api/inbox/threads/'.$thread->id.'/compose-context');
        $ctxMarketing->assertOk();
        $ctxMarketing->assertJsonPath('data.suppression.scope', 'marketing');
        $ctxMarketing->assertJsonPath('data.suppression.reason', 'unsubscribed');

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-ok'], 201),
        ]);

        $allowed = $this->postJson('/api/inbox/threads/'.$thread->id.'/reply', [
            'body_text' => 'Transactional reply despite marketing suppression.',
        ]);
        $allowed->assertCreated();
        Http::assertSentCount(1);

        // scope=all: context surfaces; reply blocked by sender.
        SuppressionWriter::write(
            Channel::Email,
            'suppressed@example.com',
            SuppressionScope::All,
            SuppressionReason::HardBounce,
        );

        $ctxAll = $this->getJson('/api/inbox/threads/'.$thread->id.'/compose-context');
        $ctxAll->assertOk();
        $ctxAll->assertJsonPath('data.suppression.scope', 'all');
        $ctxAll->assertJsonPath('data.suppression.reason', 'hard_bounce');

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'should-not-send'], 201),
        ]);

        $blocked = $this->postJson('/api/inbox/threads/'.$thread->id.'/reply', [
            'body_text' => 'Should be blocked.',
        ]);
        $blocked->assertStatus(422);
        $blocked->assertJsonPath('errors.suppression.scope', 'all');
        $blocked->assertJsonPath('errors.suppression.reason', 'hard_bounce');
        Http::assertSentCount(0);
    }
}
