<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Advertising\ConversionDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverAdConversions implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('ads');
    }

    public function handle(ConversionDelivery $delivery): void
    {
        $delivery->run();
    }
}
