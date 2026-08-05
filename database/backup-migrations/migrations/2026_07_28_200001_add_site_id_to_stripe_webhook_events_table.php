<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New stripe_webhook_events rows always carry site_id (per-site Stripe
 * signature verification). Legacy rows may be null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_webhook_events', function (Blueprint $table): void {
            $table->foreignId('site_id')->nullable()->after('id')
                ->constrained('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stripe_webhook_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
