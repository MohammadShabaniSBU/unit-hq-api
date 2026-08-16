<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->json('fact_keys')->nullable();
            $table->string('principal_verification')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table): void {
            $table->dropColumn(['fact_keys', 'principal_verification']);
        });
    }
};
