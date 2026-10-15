<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use App\Models\TemplateFamily;
use App\Models\TemplateVersion;
use App\Support\Communications\TemplatePublishValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplatePublishValidatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function uses_catalogue_samples(): void
    {
        $debt = $this->warningTokens(
            TemplatePurpose::Debt,
            'Owe {{contract.balance_owed}} pay {{pay_link}} stop {{unsubscribe_url}}',
        );
        $this->assertNotContains('contract.balance_owed', $debt);
        $this->assertNotContains('pay_link', $debt);
        $this->assertContains('unsubscribe_url', $debt);

        $newsletter = $this->warningTokens(
            TemplatePurpose::Newsletter,
            'Issue {{newsletter.issue_number}} leave {{unsubscribe_url}} owe {{contract.balance_owed}}',
        );
        $this->assertContains('contract.balance_owed', $newsletter);
        $this->assertNotContains('unsubscribe_url', $newsletter);
        $this->assertNotContains('newsletter.issue_number', $newsletter);
    }

    /**
     * @return list<string>
     */
    private function warningTokens(TemplatePurpose $purpose, string $subject): array
    {
        $family = TemplateFamily::factory()->create([
            'channel' => TemplateChannel::Email,
            'purpose' => $purpose,
        ]);
        $version = TemplateVersion::query()->create([
            'template_family_id' => $family->id,
            'version_number' => 1,
            'status' => TemplateVersionStatus::Draft,
        ]);
        $version->variants()->create([
            'template_family_id' => $family->id,
            'locale' => 'en',
            'subject' => $subject,
            'legacy_html' => '<p>Hello</p>',
        ]);

        $warnings = TemplatePublishValidator::warnings($version->fresh(['family', 'variants']));
        $tokens = [];
        foreach ($warnings as $warning) {
            foreach ($warning['tokens'] as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
