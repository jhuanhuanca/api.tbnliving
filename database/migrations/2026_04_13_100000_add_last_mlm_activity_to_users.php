<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_mlm_activity_at')->nullable()->after('updated_at');
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('UPDATE users SET last_mlm_activity_at = COALESCE(created_at, updated_at) WHERE last_mlm_activity_at IS NULL');
        } else {
            DB::table('users')->whereNull('last_mlm_activity_at')->update(['last_mlm_activity_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_mlm_activity_at');
        });
    }
};
