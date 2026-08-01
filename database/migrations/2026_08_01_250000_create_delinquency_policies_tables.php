<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delinquency_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128);
            $table->boolean('auto_release_overlock')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('delinquency_policy_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delinquency_policy_id')
                ->constrained('delinquency_policies')
                ->cascadeOnDelete();
            $table->smallInteger('offset_days');
            $table->string('action', 32);
            $table->json('params')->default('{}');
            $table->smallInteger('sort');
            $table->timestamps();

            $table->unique(['delinquency_policy_id', 'sort'], 'dps_order_idx');
            $table->unique(
                ['delinquency_policy_id', 'offset_days', 'action'],
                'dps_offset_action_idx'
            );
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->foreignId('delinquency_policy_id')
                ->nullable()
                ->after('legal_entity_id')
                ->constrained('delinquency_policies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delinquency_policy_id');
        });

        Schema::dropIfExists('delinquency_policy_steps');
        Schema::dropIfExists('delinquency_policies');
    }
};
