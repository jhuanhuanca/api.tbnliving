<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_proof_path', 500)->nullable()->after('payment_admin_notes');
            $table->string('payment_proof_mime', 120)->nullable()->after('payment_proof_path');
            $table->string('payment_proof_original_name', 255)->nullable()->after('payment_proof_mime');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_proof_path',
                'payment_proof_mime',
                'payment_proof_original_name',
            ]);
        });
    }
};
