<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\PreferredCustomerPricing;
use App\Support\ProductImageStorage;
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
                'image_url' => $p->resolveImageUrl(),
                'has_stored_image' => ProductImageStorage::existsFor($p),
                'category_id' => $p->category_id,
                'pv_points' => $p->pv_points,
                'estado' => $p->estado,
                'category' => $p->category,
            ];

            $publico = PreferredCustomerPricing::publicPrice($p);
            $precioPreferente = PreferredCustomerPricing::preferenteUnitPrice($p);

            if ($preferente) {
                $base['price'] = $precioPreferente;
                $base['precio_mostrar'] = $precioPreferente;
                $base['precio_publico'] = $publico;
                $base['precio_socio'] = PreferredCustomerPricing::socioUnitPrice($p);
                $base['price_cliente_preferente'] = $precioPreferente;
            } else {
                $base['price'] = $p->price;
                $base['precio_socio'] = $p->price;
                $base['precio_publico'] = $publico;
                if ($p->price_cliente_preferente !== null) {
                    $base['price_cliente_preferente'] = $p->price_cliente_preferente;
                }
            }

            return $base;
        });

        return response()->json(['data' => $data]);
    }
}
