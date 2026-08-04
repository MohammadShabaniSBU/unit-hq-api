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
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->timestamp('deactivated_at')->nullable()->after('password');
            $table->timestamp('last_login_at')->nullable()->after('deactivated_at');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::table('employees')->orderBy('id')->get() as $row) {
                $parts = explode(' ', trim((string) $row->name), 2);
                DB::table('employees')->where('id', $row->id)->update([
                    'first_name' => $parts[0] !== '' ? $parts[0] : 'Unknown',
                    'last_name' => $parts[1] ?? '',
                ]);
            }
        } else {
            DB::statement("
                UPDATE employees
                SET
                    first_name = CASE
                        WHEN position(' ' in trim(name)) = 0 THEN trim(name)
                        ELSE left(trim(name), position(' ' in trim(name)) - 1)
                    END,
                    last_name = CASE
                        WHEN position(' ' in trim(name)) = 0 THEN ''
                        ELSE substr(trim(name), position(' ' in trim(name)) + 1)
                    END
            ");
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->dropColumn('name');
            $table->string('password')->nullable()->change();
        });

        // Normalize emails to lowercase for case-insensitive uniqueness.
        if ($driver === 'sqlite') {
            foreach (DB::table('employees')->orderBy('id')->get() as $row) {
                DB::table('employees')->where('id', $row->id)->update([
                    'email' => strtolower((string) $row->email),
                ]);
            }
        } else {
            DB::statement('UPDATE employees SET email = lower(email)');
        }

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

        if ($driver === 'pgsql') {
            DB::statement('
                CREATE INDEX employee_invitations_open_idx
                ON employee_invitations (employee_id)
                WHERE accepted_at IS NULL AND revoked_at IS NULL
            ');
        } else {
            Schema::table('employee_invitations', function (Blueprint $table): void {
                $table->index(['employee_id', 'accepted_at', 'revoked_at'], 'employee_invitations_open_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_invitations');

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('id');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::table('employees')->orderBy('id')->get() as $row) {
                DB::table('employees')->where('id', $row->id)->update([
                    'name' => trim((string) $row->first_name.' '.(string) $row->last_name),
                ]);
            }
        } else {
            DB::statement("UPDATE employees SET name = trim(first_name || ' ' || last_name)");
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->string('name')->nullable(false)->change();
            $table->dropColumn(['first_name', 'last_name', 'deactivated_at', 'last_login_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
