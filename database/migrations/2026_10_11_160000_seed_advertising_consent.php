<?php

declare(strict_types=1);

use Database\Seeders\ConsentTextSeeder;
use Database\Seeders\SubscriptionListSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * System advertising list and consent-text v1. Idempotent with the seeders.
 * consent_texts rejects DELETE, so down() leaves the rows in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SubscriptionListSeeder)->seedAdvertising();
        (new ConsentTextSeeder)->run();
    }

    public function down(): void
    {
        //
    }
};
