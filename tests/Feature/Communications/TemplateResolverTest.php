<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Support\Communications\TemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_locale_ladder(): void
    {
        $family = TemplateFamily::query()->create([
            'channel' => TemplateChannel::Email,
            'name' => 'Ladder',
            'purpose' => TemplatePurpose::General,
        ]);
        $family->variants()->create([
            'locale' => 'fr',
            'subject' => 'FR',
            'legacy_html' => '<p>fr</p>',
        ]);
        $family->variants()->create([
            'locale' => 'en',
            'subject' => 'EN',
            'legacy_html' => '<p>en</p>',
        ]);
        $family->variants()->create([
            'locale' => 'es',
            'subject' => 'ES',
            'legacy_html' => '<p>es</p>',
        ]);
        $family->load('variants');

        $esCountry = Country::factory()->create(['code' => 'ES', 'name' => 'Spain']);
        $siteEs = Site::factory()->create(['country_id' => $esCountry->id]);

        // 1) contact locale wins
        $contact = Contact::factory()->create(['locale' => 'fr']);
        $this->assertSame('fr', TemplateResolver::variant($family, $contact, $siteEs)->locale);

        // 2) site country language
        $contactNoLocale = Contact::factory()->create(['locale' => null]);
        $this->assertSame('es', TemplateResolver::variant($family, $contactNoLocale, $siteEs)->locale);

        // 3) en fallback when site locale missing
        $familyEnOnly = TemplateFamily::query()->create([
            'channel' => TemplateChannel::Email,
            'name' => 'En only ladder',
            'purpose' => TemplatePurpose::General,
        ]);
        $familyEnOnly->variants()->create([
            'locale' => 'en',
            'subject' => 'EN',
            'legacy_html' => '<p>en</p>',
        ]);
        $familyEnOnly->load('variants');

        $deCountry = Country::factory()->create(['code' => 'DE', 'name' => 'Germany']);
        $siteDe = Site::factory()->create(['country_id' => $deCountry->id]);
        $this->assertSame('en', TemplateResolver::variant($familyEnOnly, $contactNoLocale, $siteDe)->locale);

        // 4) any fallback
        $familyFrOnly = TemplateFamily::query()->create([
            'channel' => TemplateChannel::Email,
            'name' => 'Fr only',
            'purpose' => TemplatePurpose::General,
        ]);
        $familyFrOnly->variants()->create([
            'locale' => 'fr',
            'subject' => 'FR',
            'legacy_html' => '<p>fr</p>',
        ]);
        $familyFrOnly->load('variants');
        $this->assertSame('fr', TemplateResolver::variant($familyFrOnly, $contactNoLocale, $siteDe)->locale);
    }
}
