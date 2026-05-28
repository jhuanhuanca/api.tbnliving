<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'rank_accumulated_pv')) {
                // Métrica separada para carrera de rangos (NO reemplaza lifetime_qualifying_pv).
                // Se calcula como: SUM(direct.lifetime_qualifying_pv * factor), solo directos válidos.
                $table->decimal('rank_accumulated_pv', 16, 2)->default(0)->after('lifetime_qualifying_pv');
                $table->index('rank_accumulated_pv');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'rank_accumulated_pv')) {
                $table->dropIndex(['rank_accumulated_pv']);
                $table->dropColumn('rank_accumulated_pv');
            }
        });
    }
};

