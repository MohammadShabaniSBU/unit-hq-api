<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_thread_id')->constrained('message_threads');
            $table->string('direction', 8);
            $table->string('status', 16);
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->string('from_address', 255);
            $table->string('to_address', 255);
            $table->string('provider', 32)->nullable();
            $table->foreignId('communication_account_id')->nullable()->constrained('communication_accounts')->nullOnDelete();
            $table->string('provider_message_id', 255)->nullable();
            $table->json('threading_evidence')->nullable();
            $table->string('source', 24);
            $table->json('source_ref')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->json('delivery_events')->nullable();
            $table->boolean('auto_generated')->default(false);
            $table->json('detail')->nullable();
            $table->timestamps();
            $table->index(['message_thread_id', 'created_at'], 'm_thread_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX m_provider_idx ON messages USING btree (provider, provider_message_id) WHERE (provider_message_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
