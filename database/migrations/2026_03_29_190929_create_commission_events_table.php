<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trail de auditoría inmutable (sin FK a wallet; el ledger apunta aquí).
     */
    public function up(): void
    {
        Schema::create('commission_events', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->foreignId('beneficiary_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('origin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->unsignedTinyInteger('level')->nullable();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3)->default('BOB');
            $table->string('period_key', 32)->nullable();
            $table->string('period_type', 16)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['beneficiary_user_id', 'type', 'period_key']);
            $table->index(['origin_user_id', 'type']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_events');
    }
};
