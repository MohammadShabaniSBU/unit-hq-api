<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Country\CountryAudit;
use App\Support\Country\CountryProfiles;
use Illuminate\Console\Command;

class DeploymentAuditCountryCommand extends Command
{
    protected $signature = 'deployment:audit-country';

    protected $description = 'List rows that contradict the deployment CountryProfile';

    public function handle(): int
    {
        CountryProfiles::assertConfigured();

        $rows = CountryAudit::contradictions();
        $code = CountryProfiles::current()->code();

        if ($rows === []) {
            $this->info("No contradictions against {$code}.");

            return self::SUCCESS;
        }

        $this->error(count($rows)." contradiction(s) against {$code}:");
        $this->table(
            ['kind', 'id', 'detail'],
            array_map(fn (array $row): array => [$row['kind'], $row['id'], $row['detail']], $rows),
        );

        return self::FAILURE;
    }
}
