<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->string('idempotency_key', 128)->unique();
            /**
             * credit | debit | retention | retention_release
             */
            $table->string('type', 32);
            $table->decimal('amount', 16, 2);
            $table->string('reference', 128)->nullable();
            $table->string('description', 512)->nullable();
            $table->foreignId('commission_event_id')->nullable()->constrained('commission_events')->nullOnDelete();
            $table->foreignId('withdrawal_id')->nullable()->constrained('withdrawals')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'type']);
            $table->index('commission_event_id');
            $table->index('withdrawal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
