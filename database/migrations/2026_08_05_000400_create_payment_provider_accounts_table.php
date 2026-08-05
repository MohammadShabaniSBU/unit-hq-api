<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->string('provider', 32)->default('stripe');
            $table->string('display_name', 128);
            $table->string('publishable_key', 255)->nullable();
            $table->text('secret_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_endpoint_id', 64)->nullable();
            $table->string('provider_account_id', 64)->nullable();
            $table->string('account_token', 64);
            $table->string('status', 16)->default('disconnected');
            $table->text('last_error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('account_token', 'ppa_token_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX ppa_entity_active_idx ON payment_provider_accounts USING btree (legal_entity_id, provider) WHERE is_active');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_accounts');
    }
};
