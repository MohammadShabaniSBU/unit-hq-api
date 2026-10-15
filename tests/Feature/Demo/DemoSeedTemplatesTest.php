<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Enums\TemplateChannel;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\TemplateAsset;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Support\Communications\TemplatePublishValidator;
use Database\Seeders\CelebrationAutomationsSeeder;
use Database\Seeders\DebtPlaybookSeeder;
use Database\Seeders\Demo\DemoBrand;
use Database\Seeders\Demo\DemoEmailTemplateSeeder;
use Database\Seeders\Demo\MarketingDemo;
use Database\Seeders\LeadChasePlaybookSeeder;
use Database\Seeders\SubscriptionListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoSeedTemplatesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function all_email_variants_v2(): void
    {
        $this->seedEmailWorld();

        $ids = $this->emailBlockIds();
        $this->assertNotSame([], $ids);

        $this->seed(DebtPlaybookSeeder::class);
        $this->seed(LeadChasePlaybookSeeder::class);
        $this->seed(CelebrationAutomationsSeeder::class);
        $this->seed(DemoEmailTemplateSeeder::class);
        Http::fake();
        MarketingDemo::apply();
        DemoBrand::apply();

        $this->assertSame($ids, $this->emailBlockIds());

        $variants = TemplateVariant::query()
            ->whereHas('family', fn ($query) => $query->where('channel', TemplateChannel::Email))
            ->get();
        $this->assertGreaterThan(0, $variants->count());
        foreach ($variants as $variant) {
            $this->assertIsArray($variant->blocks);
            $this->assertSame(2, $variant->blocks['version'] ?? null);
        }

        foreach ([
            'Payment reminder',
            'Enquiry thanks',
            'Offers this month',
            'Happy anniversary',
            'Happy birthday',
            'Demo oferta email',
            'Demo recordatorio email',
            'Demo boletín',
            'Rate increase notice',
            'Overdue reminder',
        ] as $name) {
            $this->assertTrue(
                TemplateFamily::query()
                    ->where('name', $name)
                    ->where('channel', TemplateChannel::Email)
                    ->exists(),
                $name,
            );
        }

        $settings = Setting::general();
        $this->assertSame(DemoBrand::ACCENT, $settings->emailAccentColor);
        $this->assertSame(DemoBrand::TEXT, $settings->emailTextColor);
        $this->assertSame(DemoBrand::BACKGROUND, $settings->emailBackgroundColor);
        $this->assertSame(DemoBrand::FONT, $settings->emailFontStack);
        $asset = TemplateAsset::query()->find($settings->emailLogoAssetId);
        $this->assertInstanceOf(TemplateAsset::class, $asset);
        $this->assertSame('logo.png', $asset->original_filename);
    }

    #[Test]
    public function new_templates_render_clean(): void
    {
        $this->seed(DemoEmailTemplateSeeder::class);

        foreach (['Rate increase notice', 'Overdue reminder'] as $name) {
            $family = TemplateFamily::query()
                ->where('name', $name)
                ->where('channel', TemplateChannel::Email)
                ->firstOrFail();
            $version = $family->currentVersion;
            $this->assertNotNull($version);
            $this->assertSame([], TemplatePublishValidator::warnings($version));
            $this->assertSame(2, $version->variants()->count());
        }
    }

    private function seedEmailWorld(): void
    {
        $this->seed(SubscriptionListSeeder::class);
        Contact::factory()->create();
        Http::fake();
        MarketingDemo::apply();

        $this->seed(DebtPlaybookSeeder::class);
        $this->seed(LeadChasePlaybookSeeder::class);
        $this->seed(CelebrationAutomationsSeeder::class);
        $this->seed(DemoEmailTemplateSeeder::class);
        DemoBrand::apply();
    }

    /**
     * @return array<int, mixed>
     */
    private function emailBlockIds(): array
    {
        return TemplateVariant::query()
            ->whereHas('family', fn ($query) => $query->where('channel', TemplateChannel::Email))
            ->orderBy('id')
            ->pluck('blocks', 'id')
            ->all();
    }
}
