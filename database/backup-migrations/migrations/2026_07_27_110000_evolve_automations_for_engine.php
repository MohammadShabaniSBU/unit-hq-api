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
        Schema::table('automations', function (Blueprint $table) {
            $table->string('status', 50)->default('draft')->after('description');
            $table->timestamp('archived_at')->nullable()->after('version');
            $table->index('status');
            $table->index('archived_at');
        });

        DB::table('automations')->where('enabled', true)->update(['status' => 'active']);
        DB::table('automations')->where('enabled', false)->update(['status' => 'inactive']);

        Schema::table('automations', function (Blueprint $table) {
            $table->dropIndex(['enabled']);
            $table->dropColumn('enabled');
        });

        $typeMap = [
            'object_creation' => 'trigger.object_created',
            'property_update' => 'trigger.object_updated',
            'schedule' => 'trigger.schedule',
            'update_object' => 'action.update_object',
            'send_email' => 'action.send_email',
        ];

        foreach ($typeMap as $from => $to) {
            DB::table('automation_nodes')->where('type', $from)->update(['type' => $to]);
        }

        DB::table('automation_edges')->whereNull('source_handle')->orWhere('source_handle', '')->update([
            'source_handle' => 'default',
        ]);

        Schema::table('automation_edges', function (Blueprint $table) {
            $table->unique(
                ['automation_id', 'source_node_id', 'source_handle'],
                'automation_edges_source_handle_unique',
            );
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('trigger_node_id')->nullable()->after('automation_id');
            $table->string('subject_type')->nullable()->after('trigger_node_id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->string('causer_type')->nullable()->after('subject_id');
            $table->unsignedBigInteger('causer_id')->nullable()->after('causer_type');
            $table->unsignedBigInteger('root_run_id')->nullable()->after('causer_id');
            $table->unsignedSmallInteger('depth')->default(0)->after('root_run_id');
            $table->text('error')->nullable()->after('completed_at');

            $table->index(['automation_id', 'status'], 'automation_runs_automation_status_index');
            $table->index(['subject_type', 'subject_id'], 'automation_runs_subject_index');
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->foreign('trigger_node_id')
                ->references('id')
                ->on('automation_nodes')
                ->nullOnDelete();
            $table->foreign('root_run_id')
                ->references('id')
                ->on('automation_runs')
                ->nullOnDelete();
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropColumn(['context', 'triggered_by']);
        });

        Schema::table('automation_run_steps', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('automation_run_steps', function (Blueprint $table) {
            $table->dropColumn('duration_ms');
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->json('context')->nullable();
            $table->string('triggered_by', 100)->nullable();
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropForeign(['trigger_node_id']);
            $table->dropForeign(['root_run_id']);
            $table->dropIndex('automation_runs_automation_status_index');
            $table->dropIndex('automation_runs_subject_index');
            $table->dropColumn([
                'trigger_node_id',
                'subject_type',
                'subject_id',
                'causer_type',
                'causer_id',
                'root_run_id',
                'depth',
                'error',
            ]);
        });

        Schema::table('automation_edges', function (Blueprint $table) {
            $table->dropUnique('automation_edges_source_handle_unique');
        });

        $typeMap = [
            'trigger.object_created' => 'object_creation',
            'trigger.object_updated' => 'property_update',
            'trigger.schedule' => 'schedule',
            'action.update_object' => 'update_object',
            'action.send_email' => 'send_email',
        ];

        foreach ($typeMap as $from => $to) {
            DB::table('automation_nodes')->where('type', $from)->update(['type' => $to]);
        }

        Schema::table('automations', function (Blueprint $table) {
            $table->boolean('enabled')->default(false)->after('description');
            $table->index('enabled');
        });

        DB::table('automations')->where('status', 'active')->update(['enabled' => true]);
        DB::table('automations')->whereIn('status', ['inactive', 'draft'])->update(['enabled' => false]);

        Schema::table('automations', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['status', 'archived_at']);
        });
    }
};
