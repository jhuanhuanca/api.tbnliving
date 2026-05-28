<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'pv_actual')) {
                $table->decimal('pv_actual', 16, 2)->default(0)->after('monthly_qualifying_pv');
            }
            if (! Schema::hasColumn('users', 'paquete_fundador_completado')) {
                $table->boolean('paquete_fundador_completado')->default(false)->after('pv_actual');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['paquete_fundador_completado', 'pv_actual'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

