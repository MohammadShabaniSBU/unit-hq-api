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
        Schema::create('stripe_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('payment_provider_account_id')->constrained('payment_provider_accounts')->cascadeOnDelete();
            $table->string('stripe_customer_id', 64);
            $table->timestamps();

            $table->unique(['contact_id', 'payment_provider_account_id'], 'sc_pair_idx');
            $table->unique('stripe_customer_id');
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('type', 24);
            // Nullable until SEPA unparks; no FK — sepa_mandates table does not exist yet.
            $table->unsignedBigInteger('sepa_mandate_id')->nullable();
            $table->string('stripe_pm_id', 64)->nullable();
            $table->foreignId('payment_provider_account_id')
                ->nullable()
                ->constrained('payment_provider_accounts')
                ->nullOnDelete();
            $table->string('display_label', 64);
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique('stripe_pm_id');
            $table->index(['contact_id', 'archived_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX pm_default_per_account_idx ON payment_methods (contact_id, payment_provider_account_id) WHERE is_default AND archived_at IS NULL'
        );

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('payment_method_id')
                ->nullable()
                ->after('currency')
                ->constrained('payment_methods')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });

        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('stripe_customers');
    }
};
