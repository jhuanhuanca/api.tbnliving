<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadership_bonus_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rank_id')->constrained('ranks')->cascadeOnDelete();

            $table->string('month_key', 7); // YYYY-MM

            // Extensible: leadership|residual|rank_bonus|...
            $table->string('bonus_type', 32);
            // initial|requalification
            $table->string('qualification_type', 32);

            $table->decimal('amount', 16, 2)->default(0);
            $table->decimal('percentage', 10, 6)->default(0);
            $table->decimal('required_pv', 16, 4)->default(0);
            $table->decimal('achieved_pv', 16, 4)->default(0);
            $table->decimal('rank_accumulated_pv', 16, 4)->default(0);

            // 0 = inicial; 1..2 = recalificación #1/#2
            $table->unsignedSmallInteger('requalification_number')->default(0);
            $table->boolean('is_initial_payment')->default(false);

            // pending|processed|failed|reversed
            $table->string('status', 24)->default('pending');
            $table->timestamp('processed_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // CRÍTICO: dedupe por usuario/rango/mes/tipo
            $table->unique(['user_id', 'rank_id', 'month_key', 'bonus_type'], 'uniq_leadership_payout_dedupe');

            $table->index(['user_id', 'month_key'], 'idx_leadership_payout_user_month');
            $table->index(['rank_id', 'month_key'], 'idx_leadership_payout_rank_month');
            $table->index(['month_key', 'bonus_type'], 'idx_leadership_payout_month_type');
            $table->index(['status', 'processed_at'], 'idx_leadership_payout_status_processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadership_bonus_payouts');
    }
};

