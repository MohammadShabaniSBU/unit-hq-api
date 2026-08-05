<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delinquency_policy_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delinquency_policy_id')->constrained('delinquency_policies')->cascadeOnDelete();
            $table->smallInteger('offset_days');
            $table->string('action', 32);
            $table->json('params')->default('{}');
            $table->smallInteger('sort');
            $table->timestamps();
            $table->unique(['delinquency_policy_id', 'offset_days', 'action'], 'dps_offset_action_idx');
            $table->unique(['delinquency_policy_id', 'sort'], 'dps_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delinquency_policy_steps');
    }
};
