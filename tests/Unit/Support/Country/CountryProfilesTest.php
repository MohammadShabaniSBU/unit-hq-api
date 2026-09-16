<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Country;

use App\Support\Country\CountryProfiles;
use App\Support\Country\EsProfile;
use App\Support\Country\FrProfile;
use App\Support\Country\GbProfile;
use App\Support\Country\UnknownCountryException;
use Tests\TestCase;

class CountryProfilesTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['deployment.country' => 'ES']);
        CountryProfiles::resetResolved();
        parent::tearDown();
    }

    public function test_missing_country_fails_with_readable_error(): void
    {
        config(['deployment.country' => '']);

        $this->expectException(UnknownCountryException::class);
        $this->expectExceptionMessage('KEEVARIS_COUNTRY is required');

        CountryProfiles::assertConfigured();
    }

    public function test_unknown_country_fails_with_readable_error(): void
    {
        config(['deployment.country' => 'XX']);

        $this->expectException(UnknownCountryException::class);
        $this->expectExceptionMessage('has no CountryProfile');

        CountryProfiles::assertConfigured();
    }

    public function test_current_resolves_configured_profile(): void
    {
        config(['deployment.country' => 'ES']);

        $this->assertInstanceOf(EsProfile::class, CountryProfiles::current());
        $this->assertSame('EUR', CountryProfiles::current()->currency());
    }

    public function test_every_profile_is_registered(): void
    {
        $this->assertInstanceOf(EsProfile::class, CountryProfiles::for(EsProfile::CODE));
        $this->assertInstanceOf(FrProfile::class, CountryProfiles::for(FrProfile::CODE));
        $this->assertInstanceOf(GbProfile::class, CountryProfiles::for(GbProfile::CODE));
    }
}
