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
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('type', 24);
            $table->unsignedBigInteger('sepa_mandate_id')->nullable();
            $table->string('stripe_pm_id', 64)->nullable();
            $table->foreignId('payment_provider_account_id')->nullable()->constrained('payment_provider_accounts')->nullOnDelete();
            $table->string('display_label', 64);
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'archived_at'], 'payment_methods_contact_id_archived_at_index');
            $table->unique('stripe_pm_id', 'payment_methods_stripe_pm_id_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX pm_default_per_account_idx ON payment_methods USING btree (contact_id, payment_provider_account_id) WHERE (is_default AND (archived_at IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
