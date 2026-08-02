<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S12-02: operator CRM wrap-up (disposition + note) keyed to a call message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_wrapups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained('messages')->cascadeOnDelete();
            $table->string('disposition', 64)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_wrapups');
    }
};
