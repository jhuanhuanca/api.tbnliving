<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binary_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('leg_position', 8)->nullable();
            $table->timestamps();

            $table->index('parent_user_id');
            $table->index(['parent_user_id', 'leg_position']);
        });

        Schema::create('binary_leg_volume_weekly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('week_key', 16);
            $table->string('leg_position', 8);
            $table->decimal('volume_pv', 16, 2)->default(0);
            $table->timestamps();

            $table->unique(['parent_user_id', 'week_key', 'leg_position'], 'binary_leg_vol_unique');
            $table->index('week_key');
        });

        Schema::create('order_binary_volume_applied', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('week_key', 16);
            $table->timestamp('applied_at')->useCurrent();
        });

        Schema::create('binary_weekly_carry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('week_key', 16);
            $table->decimal('left_carry_pv', 16, 2)->default(0);
            $table->decimal('right_carry_pv', 16, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'week_key']);
        });

        Schema::create('period_closures', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 16);
            $table->string('period_key', 32);
            $table->string('scope', 32);
            $table->string('status', 24)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['period_type', 'period_key', 'scope'], 'period_closure_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_closures');
        Schema::dropIfExists('binary_weekly_carry');
        Schema::dropIfExists('order_binary_volume_applied');
        Schema::dropIfExists('binary_leg_volume_weekly');
        Schema::dropIfExists('binary_placements');
    }
};
