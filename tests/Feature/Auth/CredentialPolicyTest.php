<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Support\Auth\Permission;
use Database\Factories\EmployeeFactory;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CredentialPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function credential_manage_required_for_stripe_and_comms(): void
    {
        $owner = Employee::factory()->manager()->create();

        $ops = Employee::factory()->withoutRoleGrant()->create();
        EmployeeFactory::grantCompanyRole($ops, 'operations_manager');

        $site = Site::factory()->create();
        $entity = LegalEntity::factory()->create();

        Sanctum::actingAs($ops);

        $senderDenied = $this->putJson("/api/sites/{$site->id}/sender-identities/email", [
            'from_name' => 'Ops Desk',
            'from_email' => 'ops@example.com',
        ]);
        $senderDenied->assertForbidden();
        $senderDenied->assertJsonPath('message', 'errors.forbidden');
        $senderDenied->assertJsonPath('data.permission', Permission::CredentialManage->value);

        $stripeDenied = $this->putJson("/api/legal-entities/{$entity->id}/stripe-settings", [
            'publishable_key' => 'pk_test_x',
            'secret_key' => 'sk_test_x',
        ]);
        $stripeDenied->assertForbidden();
        $stripeDenied->assertJsonPath('message', 'errors.forbidden');
        $stripeDenied->assertJsonPath('data.permission', Permission::CredentialManage->value);

        Sanctum::actingAs($owner);

        $senderOk = $this->putJson("/api/sites/{$site->id}/sender-identities/email", [
            'from_name' => 'Owner Desk',
            'from_email' => 'owner@example.com',
        ]);
        $this->assertNotSame(403, $senderOk->status(), 'Owner must clear CredentialManage on sender-identities');

        $stripeOk = $this->putJson("/api/legal-entities/{$entity->id}/stripe-settings", [
            'publishable_key' => 'pk_test_x',
            'secret_key' => 'sk_test_x',
        ]);
        $this->assertNotSame(403, $stripeOk->status(), 'Owner must clear CredentialManage on stripe-settings');
    }
}
