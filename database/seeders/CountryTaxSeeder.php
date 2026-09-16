<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\TaxRate;
use App\Support\Country\CountryProfiles;
use App\Support\Country\EsProfile;
use App\Support\Country\FrProfile;
use App\Support\Country\GbProfile;
use Illuminate\Database\Seeder;

/**
 * Starting catalogue only — confirm rates with the gestor before go-live.
 * Inserts versions; never updates rates in place. Idempotent on re-run.
 */
class CountryTaxSeeder extends Seeder
{
    public function run(?Employee $createdBy = null): void
    {
        $profile = CountryProfiles::current();
        $createdById = $createdBy?->id ?? Employee::query()->value('id');
        $from = now()->subYear()->toDateString();

        $catalogue = $this->catalogue($profile->code());

        foreach ($catalogue as $row) {
            $exists = TaxRate::query()
                ->where('code', $row['code'])
                ->where('jurisdiction', $row['jurisdiction'])
                ->whereNull('effective_to')
                ->exists();

            if ($exists) {
                continue;
            }

            if ($row['is_default']) {
                TaxRate::query()->where('is_default', true)->update(['is_default' => false]);
            }

            TaxRate::query()->create([
                'name' => $row['name'],
                'code' => $row['code'],
                'rate' => $row['rate'],
                'jurisdiction' => $row['jurisdiction'],
                'is_default' => $row['is_default'],
                'effective_from' => $from,
                'effective_to' => null,
                'created_by' => $createdById,
            ]);
        }
    }

    /**
     * @return list<array{name: string, code: string, rate: string, jurisdiction: string, is_default: bool}>
     */
    private function catalogue(string $code): array
    {
        return match ($code) {
            EsProfile::CODE => [[
                'name' => 'VAT (ES)',
                'code' => 'vat',
                'rate' => '21.00',
                'jurisdiction' => EsProfile::CODE,
                'is_default' => true,
            ]],
            FrProfile::CODE => [[
                'name' => 'VAT (FR)',
                'code' => 'vat',
                'rate' => '20.00',
                'jurisdiction' => FrProfile::CODE,
                'is_default' => true,
            ]],
            GbProfile::CODE => [[
                'name' => 'VAT (GB)',
                'code' => 'vat',
                'rate' => '20.00',
                'jurisdiction' => GbProfile::CODE,
                'is_default' => true,
            ]],
            default => [],
        };
    }
}
