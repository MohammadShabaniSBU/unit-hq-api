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
        Schema::create('access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_point_id')->constrained('access_points');
            $table->foreignId('contact_id')->constrained('contacts');
            $table->foreignId('contract_id')->constrained('contracts');
            $table->string('provider_grant_id', 128)->nullable();
            $table->string('state', 16);
            $table->text('last_error')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('pin')->nullable();
            $table->timestampTz('pin_shown_at')->nullable();
            $table->timestamps();
            $table->index(['access_point_id', 'contact_id'], 'ag_point_contact_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX ag_live_idx ON access_grants USING btree (access_point_id, contact_id) WHERE ((state)::text = ANY ((ARRAY[\'applying\'::character varying, \'applied\'::character varying])::text[]))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};
