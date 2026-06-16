<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ProductImageStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends Controller
{
    public function image(Product $product): Response
    {
        if ($product->estado !== 'activo' || ! ProductImageStorage::existsFor($product)) {
            abort(404);
        }

        return Storage::disk(ProductImageStorage::DISK)->response(
            (string) $product->image_path,
            $product->image_original_name ?: 'producto-'.$product->id,
            ['Content-Type' => $product->image_mime ?: 'image/jpeg'],
        );
    }
}
