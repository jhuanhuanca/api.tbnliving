<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path', 500)->nullable()->after('image_url');
            $table->string('image_mime', 128)->nullable()->after('image_path');
            $table->string('image_original_name', 255)->nullable()->after('image_mime');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_mime', 'image_original_name']);
        });
    }
};
