<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_events', function (Blueprint $table) {
            if (! Schema::hasColumn('commission_events', 'accrual_week_key')) {
                $table->string('accrual_week_key', 16)->nullable()->after('period_type');
            }
            if (! Schema::hasColumn('commission_events', 'wallet_credited_at')) {
                $table->timestamp('wallet_credited_at')->nullable()->after('created_at');
            }
        });

        // Histórico: todo lo existente se considera ya acreditado en billetera (comportamiento previo).
        if (Schema::hasColumn('commission_events', 'wallet_credited_at')) {
            DB::table('commission_events')
                ->whereNull('wallet_credited_at')
                ->update([
                    'wallet_credited_at' => DB::raw('created_at'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('commission_events', function (Blueprint $table) {
            if (Schema::hasColumn('commission_events', 'wallet_credited_at')) {
                $table->dropColumn('wallet_credited_at');
            }
            if (Schema::hasColumn('commission_events', 'accrual_week_key')) {
                $table->dropColumn('accrual_week_key');
            }
        });
    }
};
