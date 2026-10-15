<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Enums\TemplatePurpose;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Price;
use App\Models\SubscriptionList;
use App\Models\Unit;
use App\Support\Automation\RunContext;
use App\Support\Automation\SubjectTokenBag;
use App\Support\Templates\TokenCatalog;
use App\Support\Templates\TokenDefinition;
use App\Support\Templates\TokenType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TokenCatalogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_path_resolves(): void
    {
        $employee = Employee::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'ada@example.com',
            'company' => 'Analytical Engines',
        ]);
        $unit = Unit::factory()->create(['unit_number' => 'A1']);
        $price = Price::query()->create([
            'scope' => Price::SCOPE_CONTRACT,
            'amount' => '10.00',
            'currency' => 'EUR',
            'created_by' => $employee->id,
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
        ]);
        $start = $contract->start_date->format('Y-m-d');
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'base_rate' => '10.00',
            'effective_from' => $start,
            'effective_to' => null,
        ]);
        Deal::factory()->create([
            'contact_id' => $contact->id,
            'status' => DealStatus::New,
        ]);

        $contactContext = new RunContext(subjectBag: SubjectTokenBag::forContact($contact->fresh()));
        $contractBag = SubjectTokenBag::contractBag($contract->fresh());
        $contractContext = new RunContext(subjectBag: $contractBag);
        $list = new SubscriptionList([
            'key' => 'centro',
            'labels' => ['en' => 'Newsletter'],
        ]);
        $newsletter = new RunContext(subjectBag: SubjectTokenBag::newsletter($list, 3, 'en'));

        foreach (TokenCatalog::all() as $token) {
            if (str_starts_with($token->path, 'newsletter.')) {
                $this->assertNotNull($newsletter->get($token->path), $token->path);

                continue;
            }

            $this->assertNotNull($contactContext->get($token->path), $token->path);

            if (str_starts_with($token->path, 'contract.')) {
                $this->assertNotNull($contractContext->get($token->path), $token->path);
            }
        }
    }

    #[Test]
    public function availability_by_purpose(): void
    {
        $all = TemplatePurpose::cases();
        $contract = [
            TemplatePurpose::General,
            TemplatePurpose::Debt,
            TemplatePurpose::System,
            TemplatePurpose::Announcement,
            TemplatePurpose::Contract,
        ];
        $deal = [
            TemplatePurpose::General,
            TemplatePurpose::Lead,
            TemplatePurpose::Offer,
        ];
        $payLink = [
            TemplatePurpose::Debt,
            TemplatePurpose::System,
            TemplatePurpose::Contract,
        ];
        $marketing = [
            TemplatePurpose::Marketing,
            TemplatePurpose::Newsletter,
            TemplatePurpose::Announcement,
            TemplatePurpose::Contract,
        ];
        $newsletter = [TemplatePurpose::Newsletter];

        $expected = [];
        foreach ([
            'contact.first_name',
            'contact.last_name',
            'contact.name',
            'contact.email',
            'contact.company',
            'contact.id',
        ] as $path) {
            $expected[$path] = $all;
        }
        foreach ([
            'contract.id',
            'contract.balance_owed',
            'contract.currency',
            'contract.unit_name',
            'contract.unit_rate',
        ] as $path) {
            $expected[$path] = $contract;
        }
        $expected['deal.id'] = $deal;
        $expected['deal.status'] = $deal;
        $expected['pay_link'] = $payLink;
        $expected['preferences_url'] = $marketing;
        $expected['unsubscribe_url'] = $marketing;
        $expected['newsletter.issue_number'] = $newsletter;
        $expected['newsletter.name'] = $newsletter;

        $actual = array_map(
            fn (TokenDefinition $token): string => $token->path,
            TokenCatalog::all(),
        );
        $this->assertSame(array_keys($expected), $actual);

        foreach (TemplatePurpose::cases() as $purpose) {
            $available = array_map(
                fn (TokenDefinition $token): string => $token->path,
                TokenCatalog::forPurpose($purpose),
            );
            $wanted = [];
            foreach ($expected as $path => $purposes) {
                if (in_array($purpose, $purposes, true)) {
                    $wanted[] = $path;
                }
                $this->assertSame(
                    in_array($purpose, $purposes, true),
                    TokenCatalog::isAvailable($path, $purpose),
                    $purpose->value.' '.$path,
                );
            }
            $this->assertSame($wanted, $available, $purpose->value);
        }

        $this->assertFalse(TokenCatalog::isAvailable('site.name', TemplatePurpose::General));
    }

    #[Test]
    public function sample_context_matches_send_shape(): void
    {
        $roots = [
            'contact',
            'contract',
            'deal',
            'pay_link',
            'preferences_url',
            'unsubscribe_url',
            'newsletter',
        ];

        foreach (TemplatePurpose::cases() as $purpose) {
            $bag = TokenCatalog::sampleBag($purpose);
            $context = TokenCatalog::sampleContext($purpose, 'es');
            $english = TokenCatalog::sampleContext($purpose, 'en');

            $this->assertSame($bag, SubjectTokenBag::sample($purpose));

            foreach (array_keys($bag) as $root) {
                $this->assertContains($root, $roots, $purpose->value.' '.$root);
            }

            foreach (TokenCatalog::all() as $token) {
                $value = $context->get($token->path);
                $this->assertSame($value, $english->get($token->path), $token->path);

                if ($token->availableFor($purpose)) {
                    $expected = $token->type === TokenType::Number
                        ? (int) $token->sample
                        : $token->sample;
                    $this->assertSame($expected, $value, $purpose->value.' '.$token->path);
                } else {
                    $this->assertNull($value, $purpose->value.' '.$token->path);
                }
            }
        }
    }
}
