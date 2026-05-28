<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadership_rank_requalifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rank_id')->constrained('ranks')->cascadeOnDelete();

            // Primer mes en el que calificó y recibió el primer pago de liderazgo para este rango.
            $table->string('initial_qualification_month_key', 7); // YYYY-MM
            $table->timestamp('initial_qualification_at')->nullable();

            // Recalificaciones adicionales (máx 2); paid_count = 1 + requalification_count (máx 3).
            $table->unsignedSmallInteger('requalification_count')->default(0);
            $table->timestamp('last_requalified_at')->nullable();

            // Idempotencia mensual por rango: evita doble pago por el mismo month_key.
            $table->string('last_paid_month_key', 7)->nullable();
            $table->unsignedSmallInteger('leadership_bonus_paid_count')->default(0);

            $table->string('status', 32)->default('active'); // active|capped|disabled
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'rank_id'], 'uniq_leadership_requal_user_rank');
            $table->index(['user_id', 'rank_id', 'status'], 'idx_leadership_requal_status');
            $table->index(['user_id', 'last_paid_month_key'], 'idx_leadership_requal_last_paid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadership_rank_requalifications');
    }
};

