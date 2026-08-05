<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->string('channel', 255);
            $table->string('direction', 255);
            $table->timestamp('occurred_at');
            $table->text('content')->nullable();
            $table->string('summary', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->foreignId('communication_account_id')->nullable()->constrained('communication_accounts')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();
            $table->index(['contact_id', 'occurred_at'], 'interactions_contact_id_occurred_at_index');
            $table->index('provider_message_id', 'interactions_provider_message_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
