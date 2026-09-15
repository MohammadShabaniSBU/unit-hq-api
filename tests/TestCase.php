<?php

declare(strict_types=1);

namespace Tests;

use App\Support\SystemEventPartitions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function afterRefreshingDatabase()
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $cursor = Carbon::parse('2026-01-01')->startOfMonth();
        $end = now()->startOfMonth()->addMonths(2);

        while ($cursor->lte($end)) {
            SystemEventPartitions::ensureMonth($cursor);
            $cursor = $cursor->copy()->addMonth();
        }
    }
}
