<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_point_id')->nullable()->constrained('access_points')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();
            $table->string('event_type', 16);
            $table->timestampTz('occurred_at');
            $table->string('provider_credential_ref', 128)->nullable();
            $table->string('provider_point_id', 128)->nullable();
            $table->json('raw');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['contact_id', 'occurred_at'], 'ae_contact_idx');
            $table->index(['access_point_id', 'occurred_at'], 'ae_point_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
    }
};
