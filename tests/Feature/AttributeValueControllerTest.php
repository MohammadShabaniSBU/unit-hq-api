<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use App\Models\Contact;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

/**
 * Regression coverage for a real bug: these routes used to hard-require
 * Permission::SettingsManage for both read and write, so an ordinary
 * employee with only ContactView/ContactManage got a 403 viewing/editing a
 * contact's custom properties — even though the panel never checked for
 * SettingsManage before rendering that section.
 */
class AttributeValueControllerTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function contact_view_alone_is_enough_to_read_custom_properties(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactView);
        $contact = Contact::factory()->create();

        Sanctum::actingAs($employee);

        $this->getJson("/api/contact/{$contact->id}/attribute-values")
            ->assertOk();
    }

    #[Test]
    public function settings_manage_alone_is_no_longer_sufficient_or_required(): void
    {
        // An employee with neither ContactView nor SettingsManage is still denied.
        $employee = $this->employeeWithoutPermissions();
        $contact = Contact::factory()->create();

        Sanctum::actingAs($employee);

        $this->getJson("/api/contact/{$contact->id}/attribute-values")
            ->assertForbidden();
    }

    #[Test]
    public function contact_manage_is_required_to_set_a_value(): void
    {
        $definition = AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Contact,
            'key' => 'favorite_color',
            'label' => 'Favorite color',
            'type' => AttributeType::Text,
        ]);
        $contact = Contact::factory()->create();

        $viewer = $this->employeeWithPermission(Permission::ContactView);
        Sanctum::actingAs($viewer);

        $this->patchJson('/api/attribute-values', [
            'entity_type' => 'contact',
            'entity_id' => $contact->id,
            'definition_id' => $definition->id,
            'value' => 'blue',
        ])->assertForbidden();

        $manager = $this->employeeWithPermission(Permission::ContactManage);
        Sanctum::actingAs($manager);

        $this->patchJson('/api/attribute-values', [
            'entity_type' => 'contact',
            'entity_id' => $contact->id,
            'definition_id' => $definition->id,
            'value' => 'blue',
        ])->assertOk();

        $this->assertDatabaseHas('attribute_values', [
            'definition_id' => $definition->id,
            'entity_id' => $contact->id,
            'value_text' => 'blue',
        ]);
    }
}
