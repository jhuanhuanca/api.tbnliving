<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;

final class ProductInventoryService
{
    /**
     * Cantidades agregadas por producto en líneas de pedido.
     *
     * @return array<int, int>
     */
    public function aggregateProductQuantities(Order $order): array
    {
        $order->loadMissing('items');
        $required = [];

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }
            $productId = (int) $item->product_id;
            $required[$productId] = ($required[$productId] ?? 0) + (int) $item->cantidad;
        }

        ksort($required);

        return $required;
    }

    /**
     * Descuenta inventario al completar/pagar un pedido (idempotente).
     * Debe invocarse dentro de una transacción con el pedido bloqueado.
     */
    public function deductForOrder(Order $order): void
    {
        if ($order->stock_deducted_at !== null) {
            return;
        }

        $required = $this->aggregateProductQuantities($order);
        if ($required === []) {
            return;
        }

        $this->applyStockDelta($required, -1);

        $order->stock_deducted_at = now();
        $order->save();
    }

    /**
     * Restaura inventario al cancelar un pedido que ya descontó stock (idempotente).
     */
    public function restoreForOrder(Order $order): void
    {
        if ($order->stock_deducted_at === null) {
            return;
        }

        $required = $this->aggregateProductQuantities($order);
        if ($required !== []) {
            $this->applyStockDelta($required, 1);
        }

        $order->stock_deducted_at = null;
        $order->save();
    }

    /**
     * @param  array<int, int>  $quantitiesByProduct
     */
    private function applyStockDelta(array $quantitiesByProduct, int $direction): void
    {
        $productIds = array_keys($quantitiesByProduct);

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($quantitiesByProduct as $productId => $qty) {
            $product = $products->get($productId);
            if (! $product) {
                throw new InsufficientStockException("Producto #{$productId} no encontrado.");
            }

            if ($direction < 0) {
                if ($product->estado !== 'activo') {
                    throw new InsufficientStockException("{$product->name} no está disponible.");
                }
                if ((int) $product->stock < $qty) {
                    throw new InsufficientStockException(
                        "Stock insuficiente para {$product->name}. Disponible: {$product->stock}, solicitado: {$qty}."
                    );
                }

                $affected = Product::query()
                    ->where('id', $productId)
                    ->where('stock', '>=', $qty)
                    ->decrement('stock', $qty);

                if ($affected < 1) {
                    throw new InsufficientStockException(
                        "Stock insuficiente para {$product->name} (concurrencia)."
                    );
                }
            } else {
                Product::query()
                    ->where('id', $productId)
                    ->lockForUpdate()
                    ->increment('stock', $qty);
            }
        }
    }
}
