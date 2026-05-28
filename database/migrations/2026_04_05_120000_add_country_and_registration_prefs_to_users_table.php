<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable()->after('rank_id');
            $table->foreignId('registration_package_id')->nullable()->after('country_code')
                ->constrained('packages')->nullOnDelete();
            $table->string('preferred_payment_method', 32)->nullable()->after('registration_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['registration_package_id']);
            $table->dropColumn([
                'country_code',
                'registration_package_id',
                'preferred_payment_method',
            ]);
        });
    }
};
