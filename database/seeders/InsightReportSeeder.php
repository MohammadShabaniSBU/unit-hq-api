<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Insights\NativeReportSync;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Inserts one system native row per NativeReports entry.
 * Idempotent: keyed on native_key; never overwrites operator sort_order / labels / visibility.
 *
 *   php artisan db:seed --class=InsightReportSeeder
 */
class InsightReportSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        NativeReportSync::run();
    }
}
