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
        Schema::create('analytics_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('display_name', 128);
            $table->string('base_url', 255);
            $table->text('credentials');
            $table->boolean('is_default')->default(false);
            $table->string('connection_status', 24)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX analytics_accounts_default_idx ON analytics_accounts USING btree (is_default) WHERE (is_default = true AND archived_at IS NULL)'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX analytics_accounts_default_idx ON analytics_accounts (is_default) WHERE is_default = 1 AND archived_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_accounts');
    }
};
