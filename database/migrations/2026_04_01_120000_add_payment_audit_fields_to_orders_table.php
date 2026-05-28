<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 32)->nullable()->after('estado');
            $table->timestamp('payment_confirmed_at')->nullable()->after('completed_at');
            $table->foreignId('payment_confirmed_by')->nullable()->after('payment_confirmed_at')->constrained('users')->nullOnDelete();
            $table->string('payment_admin_notes', 500)->nullable()->after('payment_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['payment_confirmed_by']);
            $table->dropColumn(['payment_method', 'payment_confirmed_at', 'payment_confirmed_by', 'payment_admin_notes']);
        });
    }
};
