<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site Stripe keys. The site is the merchant of record for direct
 * charges into its own Stripe account — no Connect, no platform fees, no
 * mode column (Stripe test/live keys are just different keys, not a toggle).
 *
 * secret_key and webhook_secret are encrypted at rest and never returned raw
 * by the API — see App\Support\Credentials for masking / blank-means-
 * unchanged conventions (shared with communication_accounts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_stripe_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->string('publishable_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_endpoint_id')->nullable();
            $table->string('webhook_route_token')->nullable()->unique();
            $table->string('status')->default('disconnected');
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_stripe_settings');
    }
};
