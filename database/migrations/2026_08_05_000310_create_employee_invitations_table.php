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
        Schema::create('employee_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique('token_hash', 'employee_invitations_token_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX employee_invitations_open_idx ON employee_invitations USING btree (employee_id) WHERE ((accepted_at IS NULL) AND (revoked_at IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_invitations');
    }
};
