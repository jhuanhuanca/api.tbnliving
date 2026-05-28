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
            if (! Schema::hasColumn('users', 'account_type')) {
                $table->string('account_type', 32)->default('member')->after('mlm_role');
                $table->index('account_type');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'price_cliente_preferente')) {
                $table->decimal('price_cliente_preferente', 12, 2)->nullable()->after('price');
            }
        });

        if (Schema::hasColumn('products', 'price_cliente_preferente')) {
            DB::table('products')->whereNull('price_cliente_preferente')->orderBy('id')->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $p = (string) $row->price;
                    $cliente = bcadd($p, bcmul($p, '0.12', 4), 2);
                    DB::table('products')->where('id', $row->id)->update([
                        'price_cliente_preferente' => $cliente,
                    ]);
                }
            });
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'meta')) {
                $table->json('meta')->nullable()->after('fx_rate_to_wallet');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'meta')) {
                $table->dropColumn('meta');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'price_cliente_preferente')) {
                $table->dropColumn('price_cliente_preferente');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'account_type')) {
                $table->dropIndex(['account_type']);
                $table->dropColumn('account_type');
            }
        });
    }
};
