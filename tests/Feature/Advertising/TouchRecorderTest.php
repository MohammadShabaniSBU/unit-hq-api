<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Models\Contact;
use App\Models\MarketingTouch;
use App\Models\SignupForm;
use App\Models\Site;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\TouchRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TouchRecorderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ad_storage_off_writes_no_touch(): void
    {
        $contact = Contact::factory()->create();

        $this->record($contact, [
            'gclid' => 'should-not-store',
            'utm_source' => 'google',
            'landing_url' => 'https://example.test/?gclid=should-not-store',
        ], ['ad_storage' => false]);

        $this->assertSame(0, MarketingTouch::query()->count());
    }

    #[Test]
    public function click_id_is_stripped_from_the_landing_url(): void
    {
        $contact = Contact::factory()->create();

        $touch = $this->record($contact, [
            'gclid' => 'SECRET',
            'utm_source' => 'google',
            'landing_url' => 'https://example.test/storage?gclid=SECRET&unit=a1',
        ], ['ad_storage' => true]);

        $this->assertNotNull($touch);
        $this->assertSame('SECRET', $touch->click_ids['gclid'] ?? null);
        $this->assertStringNotContainsString('SECRET', (string) $touch->landing_url);
        $this->assertStringContainsString('unit=a1', (string) $touch->landing_url);
    }

    #[Test]
    public function platform_comes_from_the_click_id_then_the_utm_source(): void
    {
        $google = $this->record(Contact::factory()->create(), [
            'gclid' => 'g-1',
            'utm_source' => 'facebook',
        ], ['ad_storage' => true]);
        $meta = $this->record(Contact::factory()->create(), [
            'fbclid' => 'f-1',
        ], ['ad_storage' => true]);
        $fromUtm = $this->record(Contact::factory()->create(), [
            'utm_source' => 'google',
        ], ['ad_storage' => true]);
        $organic = $this->record(Contact::factory()->create(), [
            'utm_source' => 'newsletter',
        ], ['ad_storage' => true]);

        $this->assertSame(AdPlatform::GoogleAds, $google?->platform);
        $this->assertSame(AdPlatform::Meta, $meta?->platform);
        $this->assertSame(AdPlatform::GoogleAds, $fromUtm?->platform);
        $this->assertNull($organic?->platform);
    }

    #[Test]
    public function occurred_at_clamps_to_ninety_days_and_to_now(): void
    {
        $now = CarbonImmutable::parse('2026-06-15 12:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $old = $this->record(Contact::factory()->create(), [
                'gclid' => 'old',
                'utm_source' => 'google',
                'occurred_at' => '2020-01-01T00:00:00Z',
            ], ['ad_storage' => true]);
            $future = $this->record(Contact::factory()->create(), [
                'gclid' => 'future',
                'utm_source' => 'google',
                'occurred_at' => '2027-01-01T00:00:00Z',
            ], ['ad_storage' => true]);

            $this->assertTrue($old?->occurred_at->equalTo($now->subDays(90)));
            $this->assertTrue($future?->occurred_at->equalTo($now));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[Test]
    public function a_prior_visitor_row_stitches_once(): void
    {
        $key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $contact = Contact::factory()->create();
        $prior = MarketingTouch::factory()->create([
            'contact_id' => null,
            'visitor_key' => $key,
        ]);

        $this->record($contact, [
            'visitor_key' => $key,
            'gclid' => 'stitch',
            'utm_source' => 'google',
        ], ['ad_storage' => true]);

        $prior->refresh();
        $this->assertSame($contact->id, $prior->contact_id);

        $this->record(Contact::factory()->create(), [
            'visitor_key' => $key,
            'gclid' => 'again',
            'utm_source' => 'google',
        ], ['ad_storage' => true]);

        $prior->refresh();
        $this->assertSame($contact->id, $prior->contact_id);
    }

    /**
     * @param  array<string, mixed>  $touch
     * @param  array<string, mixed>  $consent
     */
    private function record(Contact $contact, array $touch, array $consent): ?MarketingTouch
    {
        $form = SignupForm::factory()->create([
            'site_id' => Site::factory(),
        ]);

        TouchRecorder::fromSubmission($form, $contact, $touch, $consent);

        return MarketingTouch::query()
            ->where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->first();
    }
}
