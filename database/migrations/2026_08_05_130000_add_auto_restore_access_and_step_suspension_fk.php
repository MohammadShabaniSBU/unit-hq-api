<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delinquency_policies', function (Blueprint $table): void {
            $table->boolean('auto_restore_access')->default(true)->after('auto_release_overlock');
        });

        Schema::table('delinquency_steps', function (Blueprint $table): void {
            $table->foreignId('access_suspension_id')
                ->nullable()
                ->after('task_id')
                ->constrained('access_suspensions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('delinquency_steps', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('access_suspension_id');
        });

        Schema::table('delinquency_policies', function (Blueprint $table): void {
            $table->dropColumn('auto_restore_access');
        });
    }
};
