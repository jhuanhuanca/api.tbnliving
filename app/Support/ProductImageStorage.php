<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ProductImageStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'product-images';

    public static function existsFor(Product $product): bool
    {
        $path = (string) ($product->image_path ?? '');

        return $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    public static function store(Product $product, UploadedFile $file): void
    {
        if ($product->image_path) {
            Storage::disk(self::DISK)->delete($product->image_path);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::uuid().'.'.$ext;
        $path = $file->storeAs(self::DIRECTORY.'/'.$product->id, $filename, self::DISK);

        if (! is_string($path) || $path === '') {
            Storage::disk(self::DISK)->makeDirectory(self::DIRECTORY.'/'.$product->id);
            $path = $file->storeAs(self::DIRECTORY.'/'.$product->id, $filename, self::DISK);
        }

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException(
                'No se pudo guardar la imagen en almacenamiento. Verifique permisos de escritura en storage/app/private.'
            );
        }

        $product->forceFill([
            'image_path' => $path,
            'image_mime' => (string) $file->getMimeType(),
            'image_original_name' => $file->getClientOriginalName(),
        ])->save();
    }

    public static function publicUrl(Product $product): ?string
    {
        if (! self::existsFor($product)) {
            return null;
        }

        return url('/api/v1/public/products/'.$product->id.'/image');
    }
}
