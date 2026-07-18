<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\RequestId;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CaptureRequestIdJob implements ShouldQueue
{
    use Queueable;

    public static ?string $captured = null;

    public function handle(): void
    {
        self::$captured = RequestId::get();
    }
}
