<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Models\Contact;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\UnitClass;
use App\Models\VoiceSession;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Communications\MessageSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\CreatesCataloguePrices;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class VoiceSendQuoteByTextToolTest extends TestCase
{
    use CreatesCataloguePrices;
    use DispatchesAgentTools;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    #[Test]
    public function sends_to_the_on_file_number_and_returns_no_figure(): void
    {
        [$site, $class, $contact, $principal, $ctx] = $this->quotedWorld();
        $this->givePrimaryPhone($contact, '+15551234417');

        $result = $this->dispatchTool('concierge', 'voice.send_quote_by_text', $principal, [
            'unit_class_id' => $class->id,
            'site_id' => $site->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame("I've sent the exact quote by text.", $result->display);
        $this->assertSame(0, preg_match('/\d/', $result->display));
        $this->assertFalse($result->facts->contains('70.00'));

        $message = Message::query()->firstOrFail();
        $this->assertSame(MessageSource::System, $message->source);
        $this->assertSame('+15551234417', $message->to_address);
        $this->assertStringContainsString('70.00', (string) $message->body_text);
        $this->assertStringNotContainsString((string) $message->body_text, $result->display);
    }

    #[Test]
    public function a_named_destination_is_ignored(): void
    {
        [$site, $class, $contact, $principal, $ctx] = $this->quotedWorld();
        $this->givePrimaryPhone($contact, '+15551234417');

        $result = $this->dispatchTool('concierge', 'voice.send_quote_by_text', $principal, [
            'unit_class_id' => $class->id,
            'site_id' => $site->id,
            'destination' => '+15550009999',
            'phone' => '+15550009999',
            'to' => '+15550009999',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('+15551234417', Message::query()->value('to_address'));
    }

    #[Test]
    public function refuses_without_a_contact(): void
    {
        [$site, $class] = $this->pricedClass();
        $principal = AgentPrincipal::anonymous($site->id, 'en');
        $ctx = $this->writeContext($principal, 'concierge');
        $this->licenseModels($ctx, $class, $site);

        $result = $this->dispatchTool('concierge', 'voice.send_quote_by_text', $principal, [
            'unit_class_id' => $class->id,
            'site_id' => $site->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::Unavailable, $result->error?->errorCode);
        $this->assertSame('crm.create_contact', $result->error?->recovery['tool'] ?? null);
        $this->assertSame(0, Message::query()->count());
    }

    #[Test]
    public function refuses_without_a_phone_or_session_number(): void
    {
        [$site, $class, $contact, $principal, $ctx] = $this->quotedWorld();

        $result = $this->dispatchTool('concierge', 'voice.send_quote_by_text', $principal, [
            'unit_class_id' => $class->id,
            'site_id' => $site->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::Unavailable, $result->error?->errorCode);
        $this->assertSame(0, Message::query()->count());
        $this->assertNotNull($contact->id);
    }

    #[Test]
    public function falls_back_to_the_voice_session_caller_number(): void
    {
        [$site, $class, $contact, $principal, $ctx] = $this->quotedWorld();
        VoiceSession::factory()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'caller_number' => '+15550008888',
            'contact_id' => $contact->id,
            'site_id' => $site->id,
        ]);

        $result = $this->dispatchTool('concierge', 'voice.send_quote_by_text', $principal, [
            'unit_class_id' => $class->id,
            'site_id' => $site->id,
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('+15550008888', Message::query()->value('to_address'));
    }

    /**
     * @return array{0: Site, 1: UnitClass, 2: Contact, 3: AgentPrincipal, 4: AgentContext}
     */
    private function quotedWorld(): array
    {
        $this->fakeCommunicationProviders();
        [$site, $class] = $this->pricedClass();
        $this->seedSmsAccount($site);
        $contact = Contact::factory()->create();
        Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
        ]);
        $principal = AgentPrincipal::channelAsserted($contact->id, $site->id, 'en');
        $ctx = $this->writeContext($principal, 'concierge');
        $this->licenseModels($ctx, $class, $site);

        return [$site, $class, $contact, $principal, $ctx];
    }

    /**
     * @return array{0: Site, 1: UnitClass}
     */
    private function pricedClass(): array
    {
        $employee = Employee::factory()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id]);
        $class = UnitClass::factory()->create(['tax_rate_code' => 'vat', 'label' => 'Small']);
        $this->createUnitClassCataloguePrice($class->id, $site->id, $employee->id, [
            'amount' => '70.00',
            'currency' => 'EUR',
        ]);
        TaxRate::query()->create([
            'name' => 'VAT ES',
            'code' => 'vat',
            'rate' => '21.00',
            'jurisdiction' => 'ES',
            'is_default' => false,
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'created_by' => $employee->id,
        ]);

        return [$site, $class];
    }
}
