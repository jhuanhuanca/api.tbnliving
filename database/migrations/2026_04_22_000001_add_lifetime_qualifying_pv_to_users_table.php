<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'lifetime_qualifying_pv')) {
                // PV acumulado histórico (no reinicia). Base para rangos/carrera.
                $table->decimal('lifetime_qualifying_pv', 16, 2)->default(0)->after('monthly_qualifying_pv');
                $table->index('lifetime_qualifying_pv');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'lifetime_qualifying_pv')) {
                $table->dropIndex(['lifetime_qualifying_pv']);
                $table->dropColumn('lifetime_qualifying_pv');
            }
        });
    }
};

