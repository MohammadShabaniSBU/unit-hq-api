<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrandingEndpointTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exposes_display_name_only(): void
    {
        Setting::setGeneral(new GeneralSettings(
            companyName: 'Camden Lock Self Storage',
            companyContactEmail: 'ops@example.com',
            phone: '+44 20 0000 0000',
        ));

        $response = $this->getJson('/api/branding');

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'company_name' => 'Camden Lock Self Storage',
                'date_format' => 'd/m/y',
            ],
        ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertSame(['company_name', 'date_format'], array_keys($data));
        $this->assertArrayNotHasKey('company_contact_email', $data);
        $this->assertArrayNotHasKey('phone', $data);
        $this->assertArrayNotHasKey('send_window_start', $data);
        $this->assertArrayNotHasKey('email_accent_color', $data);
    }
}
