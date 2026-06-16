<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\ProductImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Producto test',
            'price' => 100,
            'stock' => 5,
            'pv_points' => 10,
            'estado' => 'activo',
        ], $overrides));
    }

    public function test_legacy_image_url_is_returned_when_no_stored_image(): void
    {
        $product = $this->makeProduct([
            'image_url' => 'https://example.com/legacy.jpg',
        ]);

        $this->assertSame('https://example.com/legacy.jpg', $product->resolveImageUrl());
    }

    public function test_stored_image_takes_priority_over_legacy_url(): void
    {
        Storage::fake(ProductImageStorage::DISK);

        $product = $this->makeProduct([
            'image_url' => 'https://example.com/legacy.jpg',
        ]);

        ProductImageStorage::store($product, UploadedFile::fake()->image('producto.jpg'));

        $this->assertStringContainsString(
            '/api/v1/public/products/'.$product->id.'/image',
            (string) $product->fresh()->resolveImageUrl()
        );
    }

    public function test_admin_can_upload_product_image(): void
    {
        Storage::fake(ProductImageStorage::DISK);

        $admin = User::factory()->create(['mlm_role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->post('/api/v1/admin/products', [
            'name' => 'Producto nuevo',
            'price' => 100,
            'pv_points' => 10,
            'estado' => 'activo',
            'image' => UploadedFile::fake()->image('nuevo.png'),
        ]);

        $response->assertCreated();
        $product = Product::query()->first();
        $this->assertNotNull($product);
        $this->assertTrue(ProductImageStorage::existsFor($product));
        $this->assertStringContainsString(
            '/api/v1/public/products/'.$product->id.'/image',
            (string) $response->json('image_url_resolved')
        );
    }

    public function test_public_can_fetch_stored_product_image(): void
    {
        Storage::fake(ProductImageStorage::DISK);

        $product = $this->makeProduct();
        ProductImageStorage::store($product, UploadedFile::fake()->image('foto.jpg'));

        $response = $this->get('/api/v1/public/products/'.$product->id.'/image');

        $response->assertOk();
    }
}
