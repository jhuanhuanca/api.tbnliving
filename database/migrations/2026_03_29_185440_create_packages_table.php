<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->decimal('price', 12, 2);
            /** PV del paquete (base para comisiones y reglas de negocio) */
            $table->decimal('pv_points', 12, 2)->default(0);
            $table->decimal('commissionable_amount', 12, 2)->nullable()->comment('Monto comisionable en moneda local si difiere del precio');
            $table->string('estado', 32)->default('activo');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
