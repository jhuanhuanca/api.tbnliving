<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_events', function (Blueprint $table) {
            if (! Schema::hasColumn('commission_events', 'unique_hash')) {
                $table->string('unique_hash', 64)->nullable()->unique()->after('idempotency_key');
            }
        });

        if (Schema::hasColumn('commission_events', 'unique_hash')) {
            DB::table('commission_events')
                ->whereNull('unique_hash')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('commission_events')->where('id', $row->id)->update([
                            'unique_hash' => hash('sha256', (string) $row->idempotency_key),
                        ]);
                    }
                });
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('wallet_transactions', 'balance_before')) {
                $table->decimal('balance_before', 18, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('wallet_transactions', 'balance_after')) {
                $table->decimal('balance_after', 18, 2)->nullable()->after('balance_before');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'commissionable_pv')) {
                $table->decimal('commissionable_pv', 16, 4)->nullable()->after('pv_points');
            }
            if (! Schema::hasColumn('order_items', 'commissionable_amount')) {
                $table->decimal('commissionable_amount', 16, 4)->nullable()->after('commissionable_pv');
            }
            if (! Schema::hasColumn('order_items', 'line_currency')) {
                $table->char('line_currency', 3)->nullable()->after('commissionable_amount');
            }
            if (! Schema::hasColumn('order_items', 'fx_rate_to_wallet')) {
                $table->decimal('fx_rate_to_wallet', 18, 8)->nullable()->default(1)->after('line_currency');
            }
        });

        if (! Schema::hasTable('mlm_periods')) {
            Schema::create('mlm_periods', function (Blueprint $table) {
                $table->id();
                $table->string('period_type', 16);
                $table->string('period_key', 32);
                $table->string('status', 16)->default('open');
                $table->timestamp('closed_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['period_type', 'period_key'], 'mlm_periods_type_key_unique');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('order_commission_runs')) {
            Schema::create('order_commission_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
                $table->string('unique_hash', 64)->unique();
                $table->timestamp('processed_at')->useCurrent();
                $table->string('engine_version', 16)->default('1');
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_monthly_rank_snapshots')) {
            Schema::create('user_monthly_rank_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('month_key', 8);
                $table->foreignId('rank_id')->nullable()->constrained('ranks')->nullOnDelete();
                $table->string('rank_slug', 64)->nullable();
                $table->decimal('qualifying_pv', 16, 2)->default(0);
                $table->unsignedSmallInteger('leadership_streak_months')->default(0);
                $table->decimal('binary_left_pv', 16, 2)->nullable();
                $table->decimal('binary_right_pv', 16, 2)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'month_key'], 'user_month_rank_snap_unique');
                $table->index('month_key');
            });
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'issuer_nit')) {
                $table->string('issuer_nit', 32)->nullable()->after('numero_factura');
            }
            if (! Schema::hasColumn('invoices', 'issuer_business_name')) {
                $table->string('issuer_business_name', 255)->nullable()->after('issuer_nit');
            }
            if (! Schema::hasColumn('invoices', 'customer_document')) {
                $table->string('customer_document', 32)->nullable()->after('issuer_business_name');
            }
            if (! Schema::hasColumn('invoices', 'customer_business_name')) {
                $table->string('customer_business_name', 255)->nullable()->after('customer_document');
            }
            if (! Schema::hasColumn('invoices', 'authorization_code')) {
                $table->string('authorization_code', 64)->nullable()->after('customer_business_name');
            }
            if (! Schema::hasColumn('invoices', 'cuf')) {
                $table->string('cuf', 128)->nullable()->after('authorization_code');
            }
            if (! Schema::hasColumn('invoices', 'electronic_invoice_status')) {
                $table->string('electronic_invoice_status', 32)->nullable()->after('cuf');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach ([
                'issuer_nit', 'issuer_business_name', 'customer_document',
                'customer_business_name', 'authorization_code', 'cuf', 'electronic_invoice_status',
            ] as $col) {
                if (Schema::hasColumn('invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('user_monthly_rank_snapshots');
        Schema::dropIfExists('order_commission_runs');
        Schema::dropIfExists('mlm_periods');

        Schema::table('order_items', function (Blueprint $table) {
            foreach (['commissionable_pv', 'commissionable_amount', 'line_currency', 'fx_rate_to_wallet'] as $col) {
                if (Schema::hasColumn('order_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            foreach (['balance_before', 'balance_after'] as $col) {
                if (Schema::hasColumn('wallet_transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('commission_events', function (Blueprint $table) {
            if (Schema::hasColumn('commission_events', 'unique_hash')) {
                $table->dropUnique(['unique_hash']);
                $table->dropColumn('unique_hash');
            }
        });
    }
};
