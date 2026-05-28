<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'lifetime_purchase_pv')) {
                // PV acumulado por compras/paquetes (NO usado para rangos). Se usa para el modal de paquetes (CardProductos).
                $table->decimal('lifetime_purchase_pv', 16, 2)->default(0)->after('lifetime_qualifying_pv');
                $table->index('lifetime_purchase_pv');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'lifetime_purchase_pv')) {
                $table->dropIndex(['lifetime_purchase_pv']);
                $table->dropColumn('lifetime_purchase_pv');
            }
        });
    }
};

