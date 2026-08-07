<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table): void {
            $table->dropForeign(['communication_account_id']);
        });

        Schema::table('whatsapp_templates', function (Blueprint $table): void {
            $table->foreign('communication_account_id')
                ->references('id')
                ->on('communication_accounts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table): void {
            $table->dropForeign(['communication_account_id']);
        });

        Schema::table('whatsapp_templates', function (Blueprint $table): void {
            $table->foreign('communication_account_id')
                ->references('id')
                ->on('communication_accounts');
        });
    }
};
