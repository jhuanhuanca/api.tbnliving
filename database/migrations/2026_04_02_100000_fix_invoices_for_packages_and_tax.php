<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'tax_amount')) {
                $table->decimal('tax_amount', 15, 2)->default(0)->after('sub_total');
            }
            if (! Schema::hasColumn('invoices', 'tax_rate')) {
                $table->decimal('tax_rate', 8, 4)->default(0)->after('tax_amount');
            }
        });

        if (! Schema::hasColumn('invoice_items', 'package_id')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->foreignId('package_id')->nullable()->after('product_id')->constrained('packages')->nullOnDelete();
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            try {
                Schema::table('invoice_items', function (Blueprint $table) {
                    $table->dropForeign(['product_id']);
                });
            } catch (\Throwable) {
                //
            }
            DB::statement('ALTER TABLE invoice_items MODIFY product_id BIGINT UNSIGNED NULL');
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'tax_amount')) {
                $table->dropColumn('tax_amount');
            }
            if (Schema::hasColumn('invoices', 'tax_rate')) {
                $table->dropColumn('tax_rate');
            }
        });

        if (Schema::hasColumn('invoice_items', 'package_id')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropForeign(['package_id']);
                $table->dropColumn('package_id');
            });
        }
    }
};
