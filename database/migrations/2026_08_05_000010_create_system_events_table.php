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
        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresPartitionedTable();

            return;
        }

        Schema::create('system_events', function (Blueprint $table) {
            $table->id();
            $table->text('event');
            $table->uuid('request_id')->nullable();
            $table->text('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('request_id');
            $table->index(['event', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_events');
    }

    private function createPostgresPartitionedTable(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE system_events (
                id BIGSERIAL NOT NULL,
                event TEXT NOT NULL,
                request_id UUID NULL,
                subject_type TEXT NULL,
                subject_id BIGINT NULL,
                causer_type TEXT NULL,
                causer_id BIGINT NULL,
                payload JSONB NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
            SQL);

        $start = now()->startOfMonth();
        for ($i = 0; $i < 2; $i++) {
            $from = $start->copy()->addMonths($i);
            $to = $from->copy()->addMonth();
            $name = 'system_events_'.$from->format('Y_m');
            DB::statement(sprintf(
                "CREATE TABLE %s PARTITION OF system_events FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $from->toDateString(),
                $to->toDateString(),
            ));
            DB::statement("CREATE INDEX {$name}_request_id_idx ON {$name} (request_id)");
            DB::statement("CREATE INDEX {$name}_event_created_at_idx ON {$name} (event, created_at)");
            DB::statement("CREATE INDEX {$name}_subject_created_at_idx ON {$name} (subject_type, subject_id, created_at)");
        }
    }
};
