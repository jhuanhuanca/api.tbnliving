<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlm_member_code_counter', function (Blueprint $table) {
            $table->id();
            /** Próximo código a asignar (10 … 1_000_000) */
            $table->unsignedInteger('next_assignable')->default(10);
        });

        DB::table('mlm_member_code_counter')->insert(['next_assignable' => 10]);

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('member_code')->nullable()->unique()->after('referral_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['member_code']);
            $table->dropColumn('member_code');
        });
        Schema::dropIfExists('mlm_member_code_counter');
    }
};
