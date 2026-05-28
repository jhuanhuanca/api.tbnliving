<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            if (! Schema::hasColumn('countries', 'code')) {
                $table->char('code', 2)->nullable()->unique()->after('name');
                $table->string('flag_emoji', 8)->nullable()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            if (Schema::hasColumn('countries', 'code')) {
                $table->dropUnique(['code']);
                $table->dropColumn(['code', 'flag_emoji']);
            }
        });
    }
};
