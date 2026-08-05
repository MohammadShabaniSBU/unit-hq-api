<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('to_number', 32);
            $table->string('context_type', 24)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('aircall_call_id', 32)->nullable();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status', 16)->default('requested');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index('aircall_call_id', 'ci_correlation_idx');
            $table->index(['status', 'created_at'], 'ci_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_intents');
    }
};
