<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Analytics\AdminAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAnalyticsProductsTopTest extends TestCase
{
    use RefreshDatabase;

    public function test_top_products_uses_cantidad_and_precio_total_columns(): void
    {
        $user = User::factory()->create();

        $product = Product::query()->create([
            'name' => 'Kit Alpha',
            'price' => 50,
            'stock' => 10,
            'pv_points' => 10,
            'estado' => 'activo',
        ]);

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tipo' => 'compra',
            'cantidad' => 1,
            'total' => 100,
            'total_pv' => 10,
            'estado' => 'completado',
            'completed_at' => now(),
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'cantidad' => 2,
            'precio_unitario' => 50,
            'precio_total' => 100,
            'pv_points' => 10,
        ]);

        $top = app(AdminAnalyticsService::class)->topProducts(10);

        $this->assertCount(1, $top);
        $this->assertSame($product->id, $top->first()['product_id']);
        $this->assertSame('Kit Alpha', $top->first()['product_name']);
        $this->assertSame(2, $top->first()['quantity']);
        $this->assertSame('100.00', $top->first()['revenue']);
    }
}
