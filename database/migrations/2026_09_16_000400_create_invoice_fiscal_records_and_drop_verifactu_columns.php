<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_fiscal_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('regime', 32);
            $table->json('payload')->nullable();
            $table->string('hash', 128)->nullable();
            $table->string('prev_hash', 128)->nullable();
            $table->string('status', 16);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['invoice_id', 'regime']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['verifactu_hash', 'verifactu_prev_hash', 'verifactu_submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('verifactu_hash', 128)->nullable();
            $table->string('verifactu_prev_hash', 128)->nullable();
            $table->timestamp('verifactu_submitted_at')->nullable();
        });

        Schema::dropIfExists('invoice_fiscal_records');
    }
};
