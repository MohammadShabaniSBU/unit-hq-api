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
        Schema::create('playbooks', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 24);
            $table->string('name', 128);
            $table->boolean('is_active')->default(false);
            $table->json('enrolment_filters')->default('{}');
            $table->unsignedBigInteger('automation_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('kind');
            $table->index('is_active');
        });

        Schema::create('playbook_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_id')->constrained('playbooks')->cascadeOnDelete();
            $table->smallInteger('offset_days');
            $table->string('action', 32);
            $table->json('params')->default('{}');
            $table->smallInteger('sort');
            $table->timestamps();

            $table->unique(['playbook_id', 'sort'], 'ps_sort_idx');
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->boolean('single_active_run_per_subject')->default(false)->after('version');
            $table->json('default_guard')->nullable()->after('single_active_run_per_subject');
            $table->unsignedBigInteger('playbook_id')->nullable()->after('default_guard');
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->string('active_key')->nullable()->after('current_node_id');
        });

        // Circular FKs: playbooks ↔ automations
        Schema::table('playbooks', function (Blueprint $table) {
            $table->foreign('automation_id')
                ->references('id')
                ->on('automations')
                ->nullOnDelete();
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->foreign('playbook_id')
                ->references('id')
                ->on('playbooks')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX ar_active_enrolment_idx ON automation_runs (automation_id, active_key) WHERE active_key IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ar_active_enrolment_idx');
        }

        Schema::table('automations', function (Blueprint $table) {
            $table->dropForeign(['playbook_id']);
        });

        Schema::table('playbooks', function (Blueprint $table) {
            $table->dropForeign(['automation_id']);
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropColumn('active_key');
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->dropColumn([
                'single_active_run_per_subject',
                'default_guard',
                'playbook_id',
            ]);
        });

        Schema::dropIfExists('playbook_steps');
        Schema::dropIfExists('playbooks');
    }
};
