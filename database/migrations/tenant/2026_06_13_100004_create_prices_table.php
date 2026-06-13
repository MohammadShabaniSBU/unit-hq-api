<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prices are immutable. A rate change inserts a new row and closes the old one
 * via effective_to. Rows are never updated in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('EUR');
            $table->string('billing_period');
            $table->date('effective_from');
            $table->date('effective_to')->nullable()->index();
            $table->foreignId('created_by')->constrained('employees');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
