<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Enums\DealStatus;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Site;
use App\Support\Reports\PaidAcquisitionReport;
use App\Support\Reports\ReportFilters;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\AdvertisingDemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdvertisingDemoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeded_accounts_fill_paid_acquisition_without_network(): void
    {
        $now = CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        try {
            $site = Site::factory()->create([
                'timezone' => 'UTC',
                'currency' => 'EUR',
            ]);
            Deal::factory()->create([
                'contact_id' => Contact::factory()->create()->id,
                'site_id' => $site->id,
                'status' => DealStatus::New,
            ]);

            Http::fake();
            AdvertisingDemo::apply();
            Http::assertNothingSent();

            $result = (new PaidAcquisitionReport)->run(new ReportFilters(
                from: $now->subDays(90)->toDateString(),
                to: $now->toDateString(),
            ));

            $this->assertTrue(collect($result->rows)->contains(
                static fn (array $row): bool => $row['spend'] !== '0.00',
            ));
            $this->assertTrue(collect($result->rows)->contains(
                static fn (array $row): bool => $row['paid_leads'] > 0,
            ));
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }
}
