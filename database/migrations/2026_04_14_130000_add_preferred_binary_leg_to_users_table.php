<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'preferred_binary_leg')) {
                $table->string('preferred_binary_leg', 8)->nullable()->after('sponsor_id');
                $table->index('preferred_binary_leg');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'preferred_binary_leg')) {
                $table->dropIndex(['preferred_binary_leg']);
                $table->dropColumn('preferred_binary_leg');
            }
        });
    }
};

