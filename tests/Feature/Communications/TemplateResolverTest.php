<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Support\Communications\Exceptions\TemplateNotPublished;
use App\Support\Communications\TemplateResolver;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_locale_ladder(): void
    {
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Ladder',
            'purpose' => TemplatePurpose::General,
        ], variants: [
            ['locale' => 'fr', 'subject' => 'FR', 'legacy_html' => '<p>fr</p>'],
            ['locale' => 'en', 'subject' => 'EN', 'legacy_html' => '<p>en</p>'],
            ['locale' => 'es', 'subject' => 'ES', 'legacy_html' => '<p>es</p>'],
        ]);

        $esCountry = Country::factory()->create(['code' => 'ES', 'name' => 'Spain']);
        $siteEs = Site::factory()->create(['country_id' => $esCountry->id]);

        // 1) contact locale wins
        $contact = Contact::factory()->create(['locale' => 'fr']);
        $this->assertSame('fr', TemplateResolver::variant($family, $contact, $siteEs)->locale);

        // 2) site country language
        $contactNoLocale = Contact::factory()->create(['locale' => null]);
        $this->assertSame('es', TemplateResolver::variant($family, $contactNoLocale, $siteEs)->locale);

        // 3) en fallback when site locale missing
        $familyEnOnly = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'En only ladder',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'EN',
            'legacy_html' => '<p>en</p>',
        ]]);

        $deCountry = Country::factory()->create(['code' => 'DE', 'name' => 'Germany']);
        $siteDe = Site::factory()->create(['country_id' => $deCountry->id]);
        $this->assertSame('en', TemplateResolver::variant($familyEnOnly, $contactNoLocale, $siteDe)->locale);

        // 4) any fallback
        $familyFrOnly = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Fr only',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'fr',
            'subject' => 'FR',
            'legacy_html' => '<p>fr</p>',
        ]]);
        $this->assertSame('fr', TemplateResolver::variant($familyFrOnly, $contactNoLocale, $siteDe)->locale);
    }

    public function test_variant_uses_published_and_ignores_a_newer_draft(): void
    {
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Published wins',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Published',
            'legacy_html' => '<p>published</p>',
        ]]);

        $current = $family->currentVersion()->firstOrFail();
        $draft = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 2,
            'status' => TemplateVersionStatus::Draft,
            'based_on_version_id' => $current->id,
        ]);
        $draft->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'Draft',
            'legacy_html' => '<p>draft</p>',
        ]);

        $contact = Contact::factory()->create(['locale' => 'en']);

        $this->assertSame('Published', TemplateResolver::variant($family->fresh(), $contact, null)->subject);
        $this->assertSame('Draft', TemplateResolver::variantOf($draft, $contact, null)->subject);
    }

    public function test_variant_throws_when_only_a_draft_exists(): void
    {
        $family = TemplateFamily::factory()->create([
            'channel' => TemplateChannel::Email,
            'name' => 'Draft only',
            'purpose' => TemplatePurpose::General,
        ]);
        $draft = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $draft->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => 'Draft',
            'legacy_html' => '<p>draft</p>',
        ]);

        $this->expectException(TemplateNotPublished::class);
        TemplateResolver::variant($family, Contact::factory()->create(), null);
    }
}
