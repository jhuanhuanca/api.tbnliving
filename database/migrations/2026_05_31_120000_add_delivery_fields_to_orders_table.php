<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'delivery_mode')) {
                $table->string('delivery_mode', 16)->nullable()->after('payment_admin_notes');
            }
            if (! Schema::hasColumn('orders', 'shipping_departamento')) {
                $table->string('shipping_departamento', 120)->nullable()->after('delivery_mode');
            }
            if (! Schema::hasColumn('orders', 'shipping_ciudad')) {
                $table->string('shipping_ciudad', 120)->nullable()->after('shipping_departamento');
            }
            if (! Schema::hasColumn('orders', 'shipping_direccion')) {
                $table->string('shipping_direccion', 255)->nullable()->after('shipping_ciudad');
            }
            if (! Schema::hasColumn('orders', 'shipping_cost')) {
                $table->decimal('shipping_cost', 12, 2)->default(0)->after('shipping_direccion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $cols = ['delivery_mode', 'shipping_departamento', 'shipping_ciudad', 'shipping_direccion', 'shipping_cost'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
