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
        Schema::create('agent_channel_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->string('mode', 32);
            $table->string('audience', 32);
            $table->string('outside_hours', 32);
            $table->foreignId('updated_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('ai_agent_id');
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE agent_channel_bindings ADD CONSTRAINT agent_channel_bindings_mode_check CHECK (mode IN ('off', 'draft', 'auto'))");
            DB::statement("ALTER TABLE agent_channel_bindings ADD CONSTRAINT agent_channel_bindings_audience_check CHECK (audience IN ('known_contacts', 'existing_tenants', 'all'))");
            DB::statement("ALTER TABLE agent_channel_bindings ADD CONSTRAINT agent_channel_bindings_outside_hours_check CHECK (outside_hours IN ('inbox', 'answer'))");
            DB::statement(
                'CREATE UNIQUE INDEX agent_channel_bindings_channel_site_idx ON agent_channel_bindings (channel, COALESCE(site_id, 0)) WHERE archived_at IS NULL'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX agent_channel_bindings_channel_site_idx ON agent_channel_bindings (channel, COALESCE(site_id, 0)) WHERE archived_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_channel_bindings');
    }
};
