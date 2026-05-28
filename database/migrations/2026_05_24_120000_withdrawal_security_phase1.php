<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action', 'created_at']);
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawals', 'fee')) {
                $table->decimal('fee', 16, 2)->default(0)->after('monto');
            }
            if (! Schema::hasColumn('withdrawals', 'net_amount')) {
                $table->decimal('net_amount', 16, 2)->nullable()->after('fee');
            }
            if (! Schema::hasColumn('withdrawals', 'withdrawal_otp_id')) {
                $table->foreignId('withdrawal_otp_id')->nullable()->after('user_id')
                    ->constrained('withdrawal_otps')->nullOnDelete();
            }
            if (! Schema::hasColumn('withdrawals', 'created_ip')) {
                $table->string('created_ip', 45)->nullable();
            }
            if (! Schema::hasColumn('withdrawals', 'created_device')) {
                $table->string('created_device', 512)->nullable();
            }
            if (! Schema::hasColumn('withdrawals', 'rejected_reason')) {
                $table->text('rejected_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            if (Schema::hasColumn('withdrawals', 'withdrawal_otp_id')) {
                $table->dropConstrainedForeignId('withdrawal_otp_id');
            }
            foreach (['fee', 'net_amount', 'created_ip', 'created_device', 'rejected_reason'] as $col) {
                if (Schema::hasColumn('withdrawals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('security_logs');
        Schema::dropIfExists('withdrawal_otps');
    }
};
