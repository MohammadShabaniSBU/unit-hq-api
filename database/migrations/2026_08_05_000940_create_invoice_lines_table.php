<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('charge_id')->constrained('charges');
            $table->string('description', 255);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('net_amount', 10, 2);
            $table->decimal('tax_rate_snapshot', 5, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->decimal('gross_amount', 10, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->unique('charge_id', 'invoice_lines_charge_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
