<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Auth\Permission;
use Database\Seeders\DefaultAttributeLayoutSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class ObjectCustomizationContactNativeFieldsTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const PLACED_DEFAULT_KEYS = [
        'first_name',
        'last_name',
        'email',
        'status',
    ];

    /**
     * @var list<string>
     */
    private const NEW_NATIVE_KEYS = [
        'company',
        'locale',
        'source',
        'contact_status',
        'last_contacted_at',
        'billing_name',
        'tax_id',
        'tax_id_type',
    ];

    /**
     * Address is `contact_addresses.type = billing`, not a contact native field.
     *
     * @var list<string>
     */
    private const RETIRED_BILLING_ADDRESS_KEYS = [
        'billing_address_line1',
        'billing_address_line2',
        'billing_city',
        'billing_postal_code',
        'billing_country_code',
    ];

    #[Test]
    public function contact_customization_lists_unplaced_native_fields(): void
    {
        $this->seed(DefaultAttributeLayoutSeeder::class);

        Sanctum::actingAs($this->employeeWithPermission(Permission::SettingsManage));

        $response = $this->getJson('/api/settings/object-customization/contact')
            ->assertOk();

        $availableKeys = collect($response->json('data.available.native'))
            ->pluck('key')
            ->all();

        foreach (self::NEW_NATIVE_KEYS as $key) {
            $this->assertContains($key, $availableKeys);
        }

        foreach (self::PLACED_DEFAULT_KEYS as $key) {
            $this->assertNotContains($key, $availableKeys);
        }

        foreach (self::RETIRED_BILLING_ADDRESS_KEYS as $key) {
            $this->assertNotContains($key, $availableKeys);
        }
    }
}
