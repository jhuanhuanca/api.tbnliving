<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ProductImageStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AdminProductController extends Controller
{
    public function index()
    {
        $rows = Product::query()
            ->with('category:id,name,slug')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Product $product) => $this->adminPayload($product));

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
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $data['estado'] = $data['estado'] ?? 'activo';
        $data['stock'] = $data['stock'] ?? 0;
        if (! isset($data['price_cliente_preferente']) || $data['price_cliente_preferente'] === null || $data['price_cliente_preferente'] === '') {
            $data['price_cliente_preferente'] = number_format((float) $data['price'] * 1.12, 2, '.', '');
        }

        unset($data['image']);

        $product = DB::transaction(function () use ($request, $data) {
            $product = Product::query()->create($data);

            if ($request->hasFile('image')) {
                ProductImageStorage::store($product, $request->file('image'));
            }

            return $product;
        });

        return response()->json($this->adminPayload($product->fresh()->load('category')), 201);
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
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        unset($data['image']);

        DB::transaction(function () use ($request, $product, $data) {
            $product->update($data);

            if ($request->hasFile('image')) {
                ProductImageStorage::store($product, $request->file('image'));
            }
        });

        return response()->json($this->adminPayload($product->fresh()->load('category')));
    }

    public function destroy(Product $product)
    {
        $product->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Producto desactivado.'], 200);
    }

    public function image(Product $product): Response
    {
        if (! ProductImageStorage::existsFor($product)) {
            abort(404);
        }

        return Storage::disk(ProductImageStorage::DISK)->response(
            (string) $product->image_path,
            $product->image_original_name ?: 'producto-'.$product->id,
            ['Content-Type' => $product->image_mime ?: 'image/jpeg'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPayload(Product $product): array
    {
        $arr = $product->toArray();
        $arr['has_stored_image'] = ProductImageStorage::existsFor($product);
        $arr['image_url_resolved'] = $product->resolveImageUrl();
        $arr['image_admin_url'] = ProductImageStorage::existsFor($product)
            ? url('/api/v1/admin/products/'.$product->id.'/image')
            : null;

        return $arr;
    }
}
