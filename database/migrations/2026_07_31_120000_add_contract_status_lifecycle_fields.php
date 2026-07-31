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
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('notice_given_on')->nullable()->after('status');
            $table->unsignedSmallInteger('notice_period_days')->nullable()->after('notice_given_on');
            $table->date('move_out_on')->nullable()->after('notice_period_days');
            $table->string('ended_reason', 32)->nullable()->after('move_out_on');
        });

        DB::table('contracts')
            ->where('status', 'moved_out')
            ->update([
                'status' => 'ended',
                'ended_reason' => 'vacated',
            ]);

        DB::table('contracts')
            ->whereIn('status', ['terminated', 'expired'])
            ->update([
                'status' => 'ended',
                'ended_reason' => 'operator_terminated',
            ]);
    }

    public function down(): void
    {
        DB::table('contracts')
            ->where('status', 'ended')
            ->where('ended_reason', 'vacated')
            ->update(['status' => 'moved_out']);

        DB::table('contracts')
            ->where('status', 'ended')
            ->where('ended_reason', 'operator_terminated')
            ->update(['status' => 'terminated']);

        DB::table('contracts')
            ->whereIn('status', ['pending', 'notice_given', 'cancelled'])
            ->update(['status' => 'active']);

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'notice_given_on',
                'notice_period_days',
                'move_out_on',
                'ended_reason',
            ]);
        });
    }
};
