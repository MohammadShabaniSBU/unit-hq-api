<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Address-keyed channel suppressions + message.detail for suppressed_reason.
 * Lift never deletes — history is retained; partial unique enforces one active row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $table->string('address', 255);
            $table->string('scope', 16)->default('all');
            $table->string('reason', 32);
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('lifted_at')->nullable();
            $table->unsignedBigInteger('lifted_by')->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel', 'address'], 'channel_suppressions_channel_address_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX cs_active_idx ON channel_suppressions (channel, address) '
                .'WHERE lifted_at IS NULL'
            );
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->json('detail')->nullable()->after('source_ref');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('detail');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS cs_active_idx');
        }

        Schema::dropIfExists('channel_suppressions');
    }
};
