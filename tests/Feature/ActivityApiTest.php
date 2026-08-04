<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\User;
use App\Settings\ActivityLogSettings;
use App\Support\RecordsActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class ActivityApiTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();
    }

    public function test_settings_validation_rejects_core_channel(): void
    {
        $response = $this->patchJson('/api/settings/activity-log', [
            'channels' => ['core', 'crm'],
        ]);

        $response->assertUnprocessable();
    }

    public function test_settings_accept_optional_channels_and_retention(): void
    {
        $response = $this->patchJson('/api/settings/activity-log', [
            'channels' => ['crm', 'facility'],
            'retention_months' => 6,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.channels', ['crm', 'facility'])
            ->assertJsonPath('data.retention_months', 6);
    }

    public function test_index_hides_disabled_tier2_channels(): void
    {
        Setting::setActivityLog(new ActivityLogSettings(channels: ['crm'], retentionMonths: 12));

        $contact = Contact::factory()->create();
        RecordsActivity::core('deal.created', $contact, ['status' => 'new']);
        RecordsActivity::log(LogChannel::Crm, 'updated', $contact);
        RecordsActivity::log(LogChannel::Facility, 'updated', $contact);

        $response = $this->getJson('/api/activities');

        $response->assertOk();
        $descriptions = collect($response->json('data'))->pluck('log_name')->all();
        $this->assertContains(LogChannel::Core->value, $descriptions);
        $this->assertContains(LogChannel::Crm->value, $descriptions);
        $this->assertNotContains(LogChannel::Facility->value, $descriptions);
    }

    public function test_superadmin_can_include_disabled_channels(): void
    {
        Setting::setActivityLog(new ActivityLogSettings(channels: ['crm'], retentionMonths: 12));

        $contact = Contact::factory()->create();
        RecordsActivity::log(LogChannel::Facility, 'updated', $contact);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/activities?include_disabled=1');

        $response->assertOk();
        $this->assertContains(
            LogChannel::Facility->value,
            collect($response->json('data'))->pluck('log_name')->all()
        );
    }

    public function test_prune_tiers_never_touches_core(): void
    {
        $contact = Contact::factory()->create();

        $core = RecordsActivity::core('deal.created', $contact);
        $crm = RecordsActivity::log(LogChannel::Crm, 'updated', $contact);

        Activity::query()->whereKey($core->id)->update(['created_at' => now()->subYears(5)]);
        Activity::query()->whereKey($crm->id)->update(['created_at' => now()->subYears(5)]);

        Setting::setActivityLog(new ActivityLogSettings(channels: LogChannel::optionalValues(), retentionMonths: 3));

        $this->artisan('activitylog:prune-tiers')->assertSuccessful();

        $this->assertTrue(Activity::query()->whereKey($core->id)->exists());
        $this->assertFalse(Activity::query()->whereKey($crm->id)->exists());
    }
}
