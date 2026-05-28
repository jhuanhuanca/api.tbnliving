<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 128);
            $table->unsignedTinyInteger('sort_order')->default(0);
            /** Generaciones de residual permitidas (0–12) según rango */
            $table->unsignedTinyInteger('max_residual_generations')->default(0);
            /** Tasa sobre volumen residual (configurable por rango; 0 = usa plan global) */
            $table->decimal('residual_rate_override', 8, 6)->nullable();
            /** Base para bono de liderazgo: porcentaje sobre comisiones de downline con rango inferior (estructura flexible) */
            $table->decimal('leadership_rate', 8, 6)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranks');
    }
};
