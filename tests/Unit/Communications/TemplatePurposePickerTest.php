<?php

declare(strict_types=1);

namespace Tests\Unit\Communications;

use App\Enums\CampaignType;
use App\Enums\TemplatePurpose;
use App\Models\SubscriptionList;
use App\Support\Automation\SubjectTokenBag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplatePurposePickerTest extends TestCase
{
    #[Test]
    public function allowlist_table_covers_every_purpose(): void
    {
        $covered = [];

        foreach (self::allowlists() as $row) {
            $covered[] = $row[0];
        }

        $this->assertEqualsCanonicalizing(TemplatePurpose::cases(), $covered);
    }

    #[Test]
    #[DataProvider('allowlists')]
    public function picker_allowlist(TemplatePurpose $purpose, array $expected): void
    {
        $this->assertSame($expected, $purpose->pickerAllowlist());
    }

    #[Test]
    public function campaign_type_selects_template_purpose(): void
    {
        $this->assertSame(TemplatePurpose::Marketing, CampaignType::Campaign->templatePurpose());
        $this->assertSame(TemplatePurpose::Newsletter, CampaignType::Newsletter->templatePurpose());
        $this->assertSame(TemplatePurpose::Announcement, CampaignType::Announcement->templatePurpose());
        $this->assertSame(['marketing', 'general'], CampaignType::Campaign->purposeAllowlist());
        $this->assertSame(['newsletter', 'marketing'], CampaignType::Newsletter->purposeAllowlist());
        $this->assertSame(['announcement'], CampaignType::Announcement->purposeAllowlist());
    }

    #[Test]
    public function newsletter_sample_includes_issue_tokens(): void
    {
        $sample = SubjectTokenBag::sample(TemplatePurpose::Newsletter);

        $this->assertSame(1, $sample['newsletter']['issue_number']);
        $this->assertSame('Newsletter', $sample['newsletter']['name']);
        $this->assertArrayNotHasKey('newsletter', SubjectTokenBag::sample(TemplatePurpose::Marketing));

        $list = new SubscriptionList([
            'key' => 'centro',
            'labels' => ['en' => 'English', 'es' => 'Centro'],
        ]);

        $this->assertSame(
            ['newsletter' => ['issue_number' => 4, 'name' => 'Centro']],
            SubjectTokenBag::newsletter($list, 4, 'es-ES'),
        );
        $this->assertSame('English', SubjectTokenBag::newsletter($list, 1, 'de')['newsletter']['name']);
    }

    /**
     * @return array<string, array{0: TemplatePurpose, 1: list<string>}>
     */
    public static function allowlists(): array
    {
        return [
            'general' => [TemplatePurpose::General, ['general']],
            'debt' => [TemplatePurpose::Debt, ['debt', 'general']],
            'lead' => [TemplatePurpose::Lead, ['lead', 'general']],
            'offer' => [TemplatePurpose::Offer, ['offer']],
            'system' => [TemplatePurpose::System, ['system']],
            'contract' => [TemplatePurpose::Contract, ['contract']],
            'marketing' => [TemplatePurpose::Marketing, ['marketing', 'general']],
            'newsletter' => [TemplatePurpose::Newsletter, ['newsletter', 'marketing']],
            'announcement' => [TemplatePurpose::Announcement, ['announcement']],
        ];
    }
}
