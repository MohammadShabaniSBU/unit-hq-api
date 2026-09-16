<?php

declare(strict_types=1);

use App\Models\Site;
use App\Support\Country\CountryProfiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sites', 'country_id')) {
            return;
        }

        if (Site::query()->exists()) {
            $countryId = CountryProfiles::countryId();

            $foreign = Site::query()
                ->whereNotNull('country_id')
                ->where('country_id', '!=', $countryId)
                ->count();

            if ($foreign > 0) {
                throw new RuntimeException(
                    "{$foreign} site(s) belong to a country other than the deployment country. Run `php artisan deployment:audit-country` — this migration will not rewrite a foreign country silently.",
                );
            }

            Site::query()->whereNull('country_id')->update(['country_id' => $countryId]);
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE sites ALTER COLUMN country_id SET NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE sites ALTER COLUMN country_id DROP NOT NULL');
        }
    }
};
