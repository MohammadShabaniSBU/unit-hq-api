<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Models\AdCampaign;
use App\Models\AdConversionDelivery;
use App\Models\AdConversionEvent;
use App\Models\AdConversionMapping;
use App\Models\AdPlatformAccount;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\MarketingTouch;
use App\Models\Role;
use App\Models\Site;
use App\Support\Advertising\AdDeliveryStatus;
use App\Support\Advertising\ConversionEventKey;
use App\Support\Advertising\ConversionValueRule;
use App\Support\Auth\Permission;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class AdvertisingSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function value_without_currency_is_rejected(): void
    {
        $contact = Contact::factory()->create();

        $this->assertRejected(function () use ($contact): void {
            AdConversionEvent::query()->create([
                'event_key' => ConversionEventKey::MoveIn,
                'dedupe_id' => 'contract:1:move_in',
                'contact_id' => $contact->id,
                'subject_type' => 'contract',
                'subject_id' => 1,
                'value' => '120.00',
                'currency' => null,
                'occurred_at' => now(),
            ]);
        }, 'ace_value_has_currency');
    }

    #[Test]
    public function first_period_net_on_lead_is_rejected(): void
    {
        $account = AdPlatformAccount::factory()->create();

        $this->assertRejected(function () use ($account): void {
            AdConversionMapping::query()->create([
                'ad_platform_account_id' => $account->id,
                'event_key' => ConversionEventKey::Lead,
                'external_ref' => 'customers/1/conversionActions/2',
                'value_rule' => ConversionValueRule::FirstPeriodNet,
            ]);
        }, 'acm_value_only_move_in');
    }

    #[Test]
    public function touch_rejects_utm_source_and_delete_and_stitches_contact_once(): void
    {
        $touch = MarketingTouch::factory()->create(['contact_id' => null]);
        $contact = Contact::factory()->create();
        $other = Contact::factory()->create();

        $this->assertRejected(function () use ($touch): void {
            $touch->update(['utm_source' => 'facebook']);
        }, 'marketing_touch_immutable');

        $touch->refresh();
        $this->assertSame('google', $touch->utm_source);

        $touch->update([
            'landing_url' => null,
            'referrer_host' => null,
            'utm_term' => null,
            'utm_content' => null,
            'click_ids' => [],
        ]);
        $touch->refresh();
        $this->assertNull($touch->landing_url);
        $this->assertSame([], $touch->click_ids);

        $touch->update(['contact_id' => $contact->id]);
        $this->assertSame($contact->id, $touch->fresh()->contact_id);

        $this->assertRejected(function () use ($touch, $other): void {
            $touch->update(['contact_id' => $other->id]);
        }, 'marketing_touch_immutable');

        $this->assertSame($contact->id, $touch->fresh()->contact_id);

        $this->assertRejected(function () use ($touch): void {
            $touch->delete();
        }, 'marketing_touch_immutable');

        $this->assertNotNull(MarketingTouch::query()->find($touch->id));
    }

    #[Test]
    public function delivery_status_moves_forward_and_failed_can_return_to_pending(): void
    {
        $delivery = AdConversionDelivery::factory()->create();

        $this->assertRejected(function () use ($delivery): void {
            $delivery->update(['status' => AdDeliveryStatus::Sent]);
        }, 'ad_conversion_delivery_status');

        $delivery->refresh();
        $this->assertSame(AdDeliveryStatus::Pending, $delivery->status);

        $delivery->update(['status' => AdDeliveryStatus::Claimed]);
        $delivery->update(['status' => AdDeliveryStatus::Failed]);
        $delivery->update(['status' => AdDeliveryStatus::Pending]);

        $this->assertSame(AdDeliveryStatus::Pending, $delivery->fresh()->status);
    }

    #[Test]
    public function site_scope_hides_unmapped_campaigns(): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();

        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $account = AdPlatformAccount::factory()->create();
        $mapped = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'site_id' => $site->id,
            'external_id' => 'mapped',
        ]);
        $unmapped = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'site_id' => null,
            'external_id' => 'unmapped',
        ]);
        $elsewhere = AdCampaign::factory()->create([
            'ad_platform_account_id' => $account->id,
            'site_id' => $otherSite->id,
            'external_id' => 'elsewhere',
        ]);

        $manager = Employee::factory()->withoutRoleGrant()->create();
        $role = Role::query()->where('key', 'site_manager')->firstOrFail();
        EmployeeRole::query()->create([
            'employee_id' => $manager->id,
            'role_id' => $role->id,
            'site_id' => $site->id,
        ]);

        $visible = AdCampaign::query()
            ->visibleTo($manager, Permission::AdvertisingView)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertSame([$mapped->id], $visible);

        $company = Employee::factory()->create();
        $all = AdCampaign::query()
            ->visibleTo($company, Permission::AdvertisingView)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertEqualsCanonicalizing(
            [$mapped->id, $unmapped->id, $elsewhere->id],
            $all,
        );
    }

    private function assertRejected(callable $write, string $marker): void
    {
        try {
            DB::transaction($write);
            $this->fail('Write must be rejected.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString($marker, $exception->getMessage());
        }
    }
}
