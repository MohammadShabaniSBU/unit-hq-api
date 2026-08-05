<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site from-address / from-number configuration for a comms provider.
 * No secrets here — the provider credential lives on communication_accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_sender_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('provider_type');
            $table->foreignId('account_id')->nullable()->constrained('communication_accounts')->nullOnDelete();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_number')->nullable();
            $table->string('reply_to_email')->nullable();
            $table->string('provider_sender_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'provider_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_sender_identities');
    }
};
