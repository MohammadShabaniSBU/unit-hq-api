<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_settlement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deposit_settlement_id')->constrained('deposit_settlements')->cascadeOnDelete();
            $table->foreignId('charge_id')->constrained('charges');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->text('reason');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_settlement_lines');
    }
};
