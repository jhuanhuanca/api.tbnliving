<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sincroniza PV y precios de los cuatro paquetes (sin depender solo del seeder).
     */
    public function up(): void
    {
        $rows = [
            ['slug' => 'basico', 'name' => 'Paquete básico', 'price' => '1050.00', 'pv_points' => '100'],
            ['slug' => 'avanzado', 'name' => 'Paquete avanzado', 'price' => '2700.00', 'pv_points' => '300'],
            ['slug' => 'profesional', 'name' => 'Paquete profesional', 'price' => '5400.00', 'pv_points' => '600'],
            ['slug' => 'fundador', 'name' => 'Paquete fundador', 'price' => '10800.00', 'pv_points' => '1200'],
        ];

        foreach ($rows as $r) {
            DB::table('packages')->where('slug', $r['slug'])->update([
                'name' => $r['name'],
                'price' => $r['price'],
                'pv_points' => $r['pv_points'],
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
