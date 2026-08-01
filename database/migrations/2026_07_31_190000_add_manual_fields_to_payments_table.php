<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual payment rail (S03-06): method / received_on / reference.
 * Stripe rows leave these null until S06 enriches them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method')->nullable()->after('currency');
            $table->date('received_on')->nullable()->after('method');
            $table->string('reference')->nullable()->after('received_on');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['method', 'received_on', 'reference']);
        });
    }
};
