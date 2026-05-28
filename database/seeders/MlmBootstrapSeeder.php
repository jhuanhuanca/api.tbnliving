<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Package;
use App\Models\Product;
use App\Models\Rank;
use Illuminate\Database\Seeder;

class MlmBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(LatamCountriesSeeder::class);

        $matrix = config('mlm.residual.matrix_by_rank_slug', []);

        $careerSlugs = [
            'plata' => 'Plata',
            'oro' => 'Oro',
            'zafiro' => 'Zafiro',
            'rubi' => 'Rubí',
            'esmeralda' => 'Esmeralda',
            'diamante' => 'Diamante',
            'diamante_ejecutivo' => 'Diamante ejecutivo',
            'doble_diamante' => 'Diamante doble',
            'triple_diamante' => 'Triple diamante',
            'diamante_corona' => 'Diamante corona',
            'doble_diamante_corona' => 'Doble diamante corona',
            'triple_diamante_corona' => 'Triple diamante corona',
        ];

        $rankRows = [
            [
                'slug' => 'sin_rango',
                'name' => 'Sin rango',
                'sort_order' => 0,
                'max_residual_generations' => 0,
                'residual_rate_override' => null,
                'leadership_rate' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'activo',
                'name' => 'Socio activo',
                'sort_order' => 10,
                'max_residual_generations' => 12,
                'residual_rate_override' => null,
                'leadership_rate' => 0.10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        $order = 20;
        foreach ($careerSlugs as $slug => $label) {
            $gens = $matrix[$slug] ?? [];
            $maxGen = $gens === [] ? 0 : max(array_map('intval', array_keys($gens)));
            $rankRows[] = [
                'slug' => $slug,
                'name' => $label,
                'sort_order' => $order,
                'max_residual_generations' => $maxGen,
                'residual_rate_override' => null,
                'leadership_rate' => 0.10,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $order += 10;
        }

        Rank::query()->upsert(
            $rankRows,
            ['slug'],
            ['name', 'sort_order', 'max_residual_generations', 'residual_rate_override', 'leadership_rate', 'updated_at']
        );

        Category::query()->firstOrCreate(
            ['slug' => 'general'],
            ['name' => 'General']
        );

        $packages = [
            ['slug' => 'basico', 'name' => 'Paquete básico', 'price' => '1050.00', 'pv' => '100'],
            ['slug' => 'avanzado', 'name' => 'Paquete avanzado', 'price' => '2700.00', 'pv' => '300'],
            ['slug' => 'profesional', 'name' => 'Paquete profesional', 'price' => '5400.00', 'pv' => '600'],
            ['slug' => 'fundador', 'name' => 'Paquete fundador', 'price' => '10800.00', 'pv' => '1200'],
        ];

        foreach ($packages as $p) {
            Package::query()->updateOrCreate(
                ['slug' => $p['slug']],
                [
                    'name' => $p['name'],
                    'price' => $p['price'],
                    'pv_points' => $p['pv'],
                    'commissionable_amount' => null,
                    'estado' => 'activo',
                ]
            );
        }

        $catId = Category::query()->where('slug', 'general')->value('id');
        $productos = [
            ['name' => 'Pack Bienvenida Essential', 'description' => 'Kit de inicio con productos estrella. Ideal para nuevos socios.', 'price' => '149.00', 'pv' => '120', 'stock' => 500],
            ['name' => 'Suplemento Vitalidad', 'description' => 'Fórmula con vitaminas y minerales para energía y bienestar diario.', 'price' => '89.00', 'pv' => '70', 'stock' => 300],
            ['name' => 'Crema Facial Antiedad', 'description' => 'Hidratación intensiva y efecto lifting.', 'price' => '65.00', 'pv' => '50', 'stock' => 200],
            ['name' => 'Aceite Esencial Relax', 'description' => 'Blend natural para aromaterapia y masajes.', 'price' => '32.00', 'pv' => '25', 'stock' => 400],
            ['name' => 'Barrita Proteína Chocolate', 'description' => 'Snack saludable con proteína.', 'price' => '28.00', 'pv' => '20', 'stock' => 600],
            ['name' => 'Set Regalo Premium', 'description' => 'Caja regalo con best sellers.', 'price' => '199.00', 'pv' => '160', 'stock' => 100],
        ];
        foreach ($productos as $pr) {
            Product::query()->firstOrCreate(
                ['name' => $pr['name']],
                [
                    'description' => $pr['description'],
                    'price' => $pr['price'],
                    'stock' => $pr['stock'],
                    'image_url' => null,
                    'category_id' => $catId,
                    'pv_points' => $pr['pv'],
                    'estado' => 'activo',
                ]
            );
        }
    }
}
