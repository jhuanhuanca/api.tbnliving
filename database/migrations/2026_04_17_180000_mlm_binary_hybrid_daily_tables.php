<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binary_leg_volume_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('day_key'); // YYYY-MM-DD
            $table->string('leg_position', 8); // left|right
            $table->decimal('volume_pv', 16, 4)->default(0);
            $table->timestamps();

            $table->unique(['parent_user_id', 'day_key', 'leg_position'], 'binary_leg_daily_unique');
            $table->index('day_key');
        });

        Schema::create('order_binary_volume_applied_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->date('day_key');
            $table->timestamp('applied_at')->useCurrent();
            $table->index('day_key');
        });

        // Carry-in para un día (lo que viene arrastrado del día anterior).
        Schema::create('binary_daily_carry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('day_key');
            $table->decimal('left_carry_pv', 16, 4)->default(0);
            $table->decimal('right_carry_pv', 16, 4)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'day_key'], 'binary_daily_carry_unique');
            $table->index('day_key');
        });

        // Resultado del cálculo diario (minPV, bono diario, carry-out guardado en el carry del día siguiente).
        Schema::create('binary_daily_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('day_key');
            $table->decimal('left_eff_pv', 16, 4)->default(0);
            $table->decimal('right_eff_pv', 16, 4)->default(0);
            $table->decimal('matched_pv', 16, 4)->default(0);
            $table->decimal('daily_bonus_pv', 16, 6)->default(0);
            $table->decimal('daily_bonus_bob', 16, 6)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'day_key'], 'binary_daily_payout_unique');
            $table->index('day_key');
        });

        // Resumen semanal: acumula lo no pagado, aplica cap en pago (paid) y guarda "accumulated".
        Schema::create('binary_weekly_bonus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('week_key', 16); // o-\WW
            $table->string('month_key', 7); // YYYY-MM
            $table->decimal('weekly_bonus_bob', 16, 6)->default(0);
            $table->decimal('paid_weekly_bonus_bob', 16, 6)->default(0);
            $table->decimal('accumulated_unpaid_bob', 16, 6)->default(0);
            $table->decimal('final_accumulated_bob', 16, 6)->default(0);
            $table->boolean('month_penalty_applied')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'week_key'], 'binary_weekly_bonus_unique');
            $table->index(['month_key', 'week_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binary_weekly_bonus');
        Schema::dropIfExists('binary_daily_payouts');
        Schema::dropIfExists('binary_daily_carry');
        Schema::dropIfExists('order_binary_volume_applied_daily');
        Schema::dropIfExists('binary_leg_volume_daily');
    }
};

