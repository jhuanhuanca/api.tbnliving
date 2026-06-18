<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(int $stock = 10, array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Producto inventario',
            'price' => 50,
            'stock' => $stock,
            'pv_points' => 5,
            'estado' => 'activo',
        ], $overrides));
    }

    private function makeBuyer(): User
    {
        return User::factory()->create([
            'activation_paid_at' => now(),
            'account_status' => 'active',
            'mlm_role' => 'member',
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'mlm_role' => 'admin',
            'activation_paid_at' => now(),
        ]);
    }

    private function createPendingPaymentOrder(User $buyer, Product $product, int $qty = 1): Order
    {
        $order = Order::query()->create([
            'user_id' => $buyer->id,
            'tipo' => 'producto',
            'cantidad' => $qty,
            'total' => bcmul((string) $product->price, (string) $qty, 2),
            'total_pv' => bcmul((string) $product->pv_points, (string) $qty, 2),
            'estado' => 'pendiente_pago',
            'payment_method' => 'transferencia',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'cantidad' => $qty,
            'precio_unitario' => $product->price,
            'precio_total' => bcmul((string) $product->price, (string) $qty, 2),
            'pv_points' => bcmul((string) $product->pv_points, (string) $qty, 2),
        ]);

        return $order->fresh('items');
    }

    public function test_successful_purchase_deducts_stock_on_completion(): void
    {
        Event::fake([OrderCompleted::class]);

        $product = $this->makeProduct(8);
        $buyer = $this->makeBuyer();
        $admin = $this->makeAdmin();
        $order = $this->createPendingPaymentOrder($buyer, $product, 3);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/confirm-payment", [
                'payment_method' => 'efectivo',
            ]);

        $response->assertOk();
        $this->assertSame('completado', $order->fresh()->estado);
        $this->assertNotNull($order->fresh()->stock_deducted_at);
        $this->assertSame(5, $product->fresh()->availableStock());
        Event::assertDispatched(OrderCompleted::class);
    }

    public function test_pending_order_does_not_deduct_stock(): void
    {
        $product = $this->makeProduct(6);
        $buyer = $this->makeBuyer();

        $response = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/orders', [
            'tipo' => 'producto',
            'payment_settlement' => 'manual',
            'payment_method' => 'efectivo',
            'items' => [
                ['product_id' => $product->id, 'cantidad' => 2],
            ],
        ]);

        $response->assertSuccessful();
        $this->assertSame(6, $product->fresh()->availableStock());
        $this->assertSame('pendiente_pago', $response->json('estado'));
        $this->assertNull($response->json('stock_deducted_at'));
    }

    public function test_insufficient_stock_on_confirm_payment(): void
    {
        Event::fake([OrderCompleted::class]);

        $product = $this->makeProduct(1);
        $buyer = $this->makeBuyer();
        $admin = $this->makeAdmin();
        $order = $this->createPendingPaymentOrder($buyer, $product, 3);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/confirm-payment");

        $response->assertStatus(422);
        $this->assertStringContainsString('Stock insuficiente', (string) $response->json('message'));
        $this->assertSame('pendiente_pago', $order->fresh()->estado);
        $this->assertSame(1, $product->fresh()->availableStock());
        Event::assertNotDispatched(OrderCompleted::class);
    }

    public function test_concurrent_purchases_prevent_overselling(): void
    {
        Event::fake([OrderCompleted::class]);

        $product = $this->makeProduct(1);
        $buyerA = $this->makeBuyer();
        $buyerB = User::factory()->create([
            'activation_paid_at' => now(),
            'account_status' => 'active',
            'mlm_role' => 'member',
        ]);
        $admin = $this->makeAdmin();

        $orderA = $this->createPendingPaymentOrder($buyerA, $product, 1);
        $orderB = $this->createPendingPaymentOrder($buyerB, $product, 1);

        $first = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$orderA->id}/confirm-payment");
        $first->assertOk();

        $second = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$orderB->id}/confirm-payment");
        $second->assertStatus(422);

        $this->assertSame(0, $product->fresh()->availableStock());
        $this->assertGreaterThanOrEqual(0, $product->fresh()->stock);
        $this->assertSame('completado', $orderA->fresh()->estado);
        $this->assertSame('pendiente_pago', $orderB->fresh()->estado);
    }

    public function test_cancel_completed_order_restores_stock(): void
    {
        Event::fake([OrderCompleted::class]);

        $product = $this->makeProduct(4);
        $buyer = $this->makeBuyer();
        $admin = $this->makeAdmin();
        $order = $this->createPendingPaymentOrder($buyer, $product, 2);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/confirm-payment")
            ->assertOk();

        $this->assertSame(2, $product->fresh()->availableStock());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'notas' => 'Devolución de prueba',
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame('cancelado', $order->estado);
        $this->assertNull($order->stock_deducted_at);
        $this->assertSame(4, $product->fresh()->availableStock());
    }

    public function test_catalog_returns_real_availability(): void
    {
        $product = $this->makeProduct(3);
        $buyer = $this->makeBuyer();

        $response = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/products');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame(3, $row['stock']);
        $this->assertSame(3, $row['stock_available']);
        $this->assertTrue($row['in_stock']);
    }

    public function test_immediate_purchase_deducts_stock(): void
    {
        Event::fake([OrderCompleted::class]);

        $product = $this->makeProduct(5);
        $buyer = $this->makeBuyer();

        $response = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/orders', [
            'tipo' => 'producto',
            'payment_settlement' => 'immediate',
            'payment_method' => 'online',
            'items' => [
                ['product_id' => $product->id, 'cantidad' => 2],
            ],
        ]);

        $response->assertSuccessful();
        $this->assertSame('completado', $response->json('estado'));
        $this->assertSame(3, $product->fresh()->availableStock());
        $this->assertGreaterThanOrEqual(0, $product->fresh()->stock);
    }
}
