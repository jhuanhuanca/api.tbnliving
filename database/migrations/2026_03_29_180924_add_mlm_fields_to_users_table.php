<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unifica identidad MLM en `users` (evita duplicar con `usuarios`).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 32)->nullable()->unique()->after('password');
            $table->string('document_id', 64)->nullable()->unique()->after('referral_code');
            $table->string('phone', 32)->nullable()->after('document_id');
            $table->date('birth_date')->nullable()->after('phone');
            $table->foreignId('sponsor_id')->nullable()->after('birth_date')
                ->constrained('users')->nullOnDelete();
            $table->string('mlm_role', 32)->default('member')->after('sponsor_id');
            $table->string('account_status', 32)->default('pending')->after('mlm_role');
            $table->boolean('is_mlm_qualified')->default(false)->after('account_status');
            $table->char('last_qualification_month', 7)->nullable()->after('is_mlm_qualified');
            $table->decimal('monthly_qualifying_pv', 12, 2)->default(0)->after('last_qualification_month');
            $table->foreignId('rank_id')->nullable()->after('monthly_qualifying_pv')
                ->constrained('ranks')->nullOnDelete();

            $table->index('sponsor_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['sponsor_id']);
            $table->dropForeign(['rank_id']);
            $table->dropIndex(['sponsor_id']);
            $table->dropColumn([
                'referral_code',
                'document_id',
                'phone',
                'birth_date',
                'sponsor_id',
                'mlm_role',
                'account_status',
                'is_mlm_qualified',
                'last_qualification_month',
                'monthly_qualifying_pv',
                'rank_id',
            ]);
        });
    }
};
