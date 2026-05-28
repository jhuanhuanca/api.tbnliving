<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_payment_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id');
            $table->string('token_hash', 64)->unique(); // sha256
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('used_by_user_id')->nullable();
            $table->unsignedBigInteger('used_order_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'expires_at']);
            $table->index(['used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_payment_tokens');
    }
};

