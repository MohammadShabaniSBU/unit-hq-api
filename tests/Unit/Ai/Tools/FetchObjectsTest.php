<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\FetchObjects;
use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Site;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class FetchObjectsTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function fetches_contacts_when_employee_has_permission(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactView);
        Contact::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $result = json_decode((new FetchObjects($employee))->handle(new Request([
            'object_type' => 'contact',
            'search' => 'Ada',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total']);
        $this->assertSame('Ada Lovelace', $result['results'][0]['name']);
    }

    #[Test]
    public function denies_fetching_deals_without_deal_manage(): void
    {
        $employee = $this->employeeWithoutPermissions();
        Deal::factory()->create();

        $result = json_decode((new FetchObjects($employee))->handle(new Request([
            'object_type' => 'deal',
        ])), true);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function rejects_unknown_object_type_instead_of_running_an_unscoped_query(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactView);

        $result = json_decode((new FetchObjects($employee))->handle(new Request([
            'object_type' => 'employee',
        ])), true);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function site_scoped_employee_only_sees_deals_at_their_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $employee = $this->employeeWithSiteScopedPermission(Permission::DealManage, $siteA);

        Deal::factory()->create(['site_id' => $siteA->id]);
        Deal::factory()->create(['site_id' => $siteB->id]);

        $result = json_decode((new FetchObjects($employee))->handle(new Request([
            'object_type' => 'deal',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total']);
    }

    #[Test]
    public function fetches_custom_properties_for_a_contact(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactView);
        $contact = Contact::factory()->create();
        $definition = AttributeDefinition::query()->create([
            'entity_type' => AttributeEntityType::Contact,
            'key' => 'favorite_color',
            'label' => 'Favorite color',
            'type' => AttributeType::Text,
        ]);
        $definition->values()->create([
            'entity_id' => $contact->id,
            'value_text' => 'blue',
        ]);

        $result = json_decode((new FetchObjects($employee))->handle(new Request([
            'object_type' => 'custom_property',
            'entity_type' => 'contact',
            'entity_id' => $contact->id,
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total']);
    }

    #[Test]
    public function denies_custom_property_fetch_without_the_entitys_view_permission(): void
    {
        $employee = $this->employeeWithoutPermissions();
        $contact = Contact::factory()->create();

        $result = json_decode((new FetchObjects($employee))->handle(new Request([
            'object_type' => 'custom_property',
            'entity_type' => 'contact',
            'entity_id' => $contact->id,
        ])), true);

        $this->assertFalse($result['success']);
    }
}
