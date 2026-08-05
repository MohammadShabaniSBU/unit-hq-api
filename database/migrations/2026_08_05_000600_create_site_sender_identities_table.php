<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_sender_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('channel', 255);
            $table->foreignId('account_id')->nullable()->constrained('communication_accounts')->nullOnDelete();
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->string('from_number', 255)->nullable();
            $table->string('reply_to_email', 255)->nullable();
            $table->string('provider_sender_id', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'channel'], 'site_sender_identities_site_id_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_sender_identities');
    }
};
