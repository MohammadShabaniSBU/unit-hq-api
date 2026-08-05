<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comms_triage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('communication_account_id')->constrained('communication_accounts')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_message_id', 255);
            $table->string('channel', 16);
            $table->string('sender_value', 255);
            $table->json('preview');
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->foreignId('resolved_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('resolved_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_message_id'], 'comms_triage_provider_message_unique');
            $table->index(['status', 'created_at'], 'comms_triage_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comms_triage');
    }
};
