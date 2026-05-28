<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class AdminProductController extends Controller
{
    public function index()
    {
        $rows = Product::query()
            ->with('category:id,name,slug')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'price_cliente_preferente' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'pv_points' => ['required', 'numeric', 'min:0'],
            'estado' => ['nullable', 'string', 'in:activo,inactivo'],
        ]);

        $data['estado'] = $data['estado'] ?? 'activo';
        $data['stock'] = $data['stock'] ?? 0;
        if (! isset($data['price_cliente_preferente']) || $data['price_cliente_preferente'] === null || $data['price_cliente_preferente'] === '') {
            $data['price_cliente_preferente'] = bcadd((string) $data['price'], bcmul((string) $data['price'], '0.12', 4), 2);
        }

        $product = Product::query()->create($data);

        return response()->json($product->load('category'), 201);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'price_cliente_preferente' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'pv_points' => ['sometimes', 'numeric', 'min:0'],
            'estado' => ['sometimes', 'string', 'in:activo,inactivo'],
        ]);

        $product->update($data);

        return response()->json($product->fresh()->load('category'));
    }

    public function destroy(Product $product)
    {
        $product->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Producto desactivado.'], 200);
    }
}
