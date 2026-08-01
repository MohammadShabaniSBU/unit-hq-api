<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S10 defect: staged outbound attachments need message_id null until linked on send.
 * Not an S11 schema expansion — required for the inbox composer staging lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->dropForeign(['message_id']);
        });

        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->foreignId('message_id')->nullable()->change();
            $table->foreign('message_id')
                ->references('id')
                ->on('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->dropForeign(['message_id']);
        });

        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->foreignId('message_id')->nullable(false)->change();
            $table->foreign('message_id')
                ->references('id')
                ->on('messages');
        });
    }
};
