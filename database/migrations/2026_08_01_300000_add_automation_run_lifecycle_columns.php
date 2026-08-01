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
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->json('guard')->nullable()->after('trigger_payload');
            $table->string('cancel_cause', 32)->nullable()->after('error');
            $table->foreignId('cancelled_by')
                ->nullable()
                ->after('cancel_cause')
                ->constrained('employees')
                ->nullOnDelete();
            $table->timestampTz('waiting_until')->nullable()->after('cancelled_by');
            $table->foreignId('current_node_id')
                ->nullable()
                ->after('waiting_until')
                ->constrained('automation_nodes')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX ar_waiting_idx ON automation_runs (waiting_until) WHERE status = \'waiting\'',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ar_waiting_idx');
        }

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropForeign(['current_node_id']);
            $table->dropColumn([
                'guard',
                'cancel_cause',
                'cancelled_by',
                'waiting_until',
                'current_node_id',
            ]);
        });
    }
};
