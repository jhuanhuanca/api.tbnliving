<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class LatamCountriesSeeder extends Seeder
{
    /** @var list<array{code:string,name:string,flag:string}> */
    protected array $rows = [
        ['code' => 'AR', 'name' => 'Argentina', 'flag' => '🇦🇷'],
        ['code' => 'BO', 'name' => 'Bolivia', 'flag' => '🇧🇴'],
        ['code' => 'BR', 'name' => 'Brasil', 'flag' => '🇧🇷'],
        ['code' => 'CL', 'name' => 'Chile', 'flag' => '🇨🇱'],
        ['code' => 'CO', 'name' => 'Colombia', 'flag' => '🇨🇴'],
        ['code' => 'CR', 'name' => 'Costa Rica', 'flag' => '🇨🇷'],
        ['code' => 'CU', 'name' => 'Cuba', 'flag' => '🇨🇺'],
        ['code' => 'DO', 'name' => 'Rep. Dominicana', 'flag' => '🇩🇴'],
        ['code' => 'EC', 'name' => 'Ecuador', 'flag' => '🇪🇨'],
        ['code' => 'SV', 'name' => 'El Salvador', 'flag' => '🇸🇻'],
        ['code' => 'GT', 'name' => 'Guatemala', 'flag' => '🇬🇹'],
        ['code' => 'HN', 'name' => 'Honduras', 'flag' => '🇭🇳'],
        ['code' => 'MX', 'name' => 'México', 'flag' => '🇲🇽'],
        ['code' => 'NI', 'name' => 'Nicaragua', 'flag' => '🇳🇮'],
        ['code' => 'PA', 'name' => 'Panamá', 'flag' => '🇵🇦'],
        ['code' => 'PY', 'name' => 'Paraguay', 'flag' => '🇵🇾'],
        ['code' => 'PE', 'name' => 'Perú', 'flag' => '🇵🇪'],
        ['code' => 'PR', 'name' => 'Puerto Rico', 'flag' => '🇵🇷'],
        ['code' => 'UY', 'name' => 'Uruguay', 'flag' => '🇺🇾'],
        ['code' => 'VE', 'name' => 'Venezuela', 'flag' => '🇻🇪'],
        ['code' => 'ES', 'name' => 'España', 'flag' => '🇪🇸'],
    ];

    public function run(): void
    {
        foreach ($this->rows as $row) {
            Country::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'flag_emoji' => $row['flag'],
                ]
            );
        }
    }
}
