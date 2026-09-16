<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Country\CountryProfiles;
use Illuminate\Console\Command;

class DeploymentCountryCommand extends Command
{
    protected $signature = 'deployment:country';

    protected $description = 'Print the current CountryProfile';

    public function handle(): int
    {
        $profile = CountryProfiles::current();
        $identity = CountryProfiles::identity();

        $this->table(
            ['field', 'value'],
            [
                ['code', $profile->code()],
                ['currency', $profile->currency()],
                ['default_locale', $profile->defaultLocale()],
                ['allowed_timezones', implode(', ', $profile->allowedTimezones())],
                ['scheduler_timezone', $profile->schedulerTimezone()],
                ['billing_run_at', $profile->billingRunAt()],
                ['fiscal_regime', $profile->fiscalRegime()],
                ['payment_rails', implode(', ', $profile->paymentRails())],
                ['delinquency_flavour', $profile->delinquencyFlavour()],
                ['locked', $identity?->isLocked() ? 'yes' : 'no'],
                ['env', (string) config('deployment.country')],
            ],
        );

        return self::SUCCESS;
    }
}
