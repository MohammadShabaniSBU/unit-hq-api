<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\SetCustomProperty;
use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Contact;
use App\Models\Deal;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class SetCustomPropertyTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function sets_value_when_employee_can_manage_the_entity(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $contact = Contact::factory()->create();
        $definition = AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Contact,
            'key' => 'favorite_color',
            'label' => 'Favorite color',
            'type' => AttributeType::Text,
        ]);

        $result = json_decode((new SetCustomProperty($employee))->handle(new Request([
            'entity_type' => 'contact',
            'entity_id' => $contact->id,
            'definition_id' => $definition->id,
            'value' => 'blue',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('attribute_values', [
            'definition_id' => $definition->id,
            'entity_id' => $contact->id,
            'value_text' => 'blue',
        ]);
    }

    #[Test]
    public function denies_setting_a_deal_property_without_deal_manage(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $deal = Deal::factory()->create();
        $definition = AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Deal,
            'key' => 'lead_source',
            'label' => 'Lead source',
            'type' => AttributeType::Text,
        ]);

        $result = json_decode((new SetCustomProperty($employee))->handle(new Request([
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'definition_id' => $definition->id,
            'value' => 'referral',
        ])), true);

        $this->assertFalse($result['success'], 'ContactManage alone must not authorize setting a custom property on a Deal.');
        $this->assertSame(0, AttributeValue::query()->count());
    }
}
