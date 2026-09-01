<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_principal_promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_conversation_id')->constrained('agent_conversations')->cascadeOnDelete();
            $table->foreignId('agent_conversation_message_id')->nullable()->constrained('agent_conversation_messages')->nullOnDelete();
            $table->unsignedInteger('turn')->nullable();
            $table->unsignedInteger('seq');
            $table->string('from_level', 32);
            $table->string('to_level', 32);
            $table->string('method', 32);
            $table->string('model', 128)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['agent_conversation_id', 'seq']);
            $table->index('agent_conversation_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_principal_promotions');
    }
};
