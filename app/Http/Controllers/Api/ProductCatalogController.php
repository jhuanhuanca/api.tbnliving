<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductCatalogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $preferente = $user && $user->isPreferredCustomer();

        $products = Product::query()
            ->with('category:id,name,slug')
            ->where('estado', 'activo')
            ->orderBy('name')
            ->get();

        $data = $products->map(function (Product $p) use ($preferente) {
            $base = [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'stock' => $p->stock,
                'image_url' => $p->image_url,
                'category_id' => $p->category_id,
                'pv_points' => $p->pv_points,
                'estado' => $p->estado,
                'category' => $p->category,
            ];

            if ($preferente) {
                $cliente = $p->price_cliente_preferente ?? $p->price;
                $base['price'] = $cliente;
                $base['precio_mostrar'] = $cliente;
                $base['precio_cliente_preferente'] = $cliente;
            } else {
                $base['price'] = $p->price;
                $base['precio_socio'] = $p->price;
                if ($p->price_cliente_preferente !== null) {
                    $base['price_cliente_preferente'] = $p->price_cliente_preferente;
                }
            }

            return $base;
        });

        return response()->json(['data' => $data]);
    }
}
