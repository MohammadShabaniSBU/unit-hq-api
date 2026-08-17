<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_provisioned_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('blueprint_key', 64);
            $table->foreignId('analytics_account_id')->constrained('analytics_accounts')->cascadeOnDelete();
            $table->foreignId('insight_report_id')->nullable()->constrained('insight_reports')->nullOnDelete();
            $table->string('resource_kind', 16);
            $table->string('resource_ref', 64);
            $table->json('card_refs');
            $table->string('definition_hash', 64);
            $table->timestamp('provisioned_at');
            $table->timestamps();

            $table->unique(['blueprint_key', 'analytics_account_id'], 'insight_provisioned_blueprint_account_uidx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_provisioned_resources');
    }
};
