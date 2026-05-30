<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MlmBootstrapSeeder::class);
        $this->call(MlmMasterNetworkSeeder::class);

        $rankId = Rank::query()->where('slug', 'activo')->value('id');

        /** @var int|null $boliviaId */
        $boliviaId = Country::query()->where('code', 'BO')->value('id');

        User::query()->updateOrCreate(
            ['email' => 'admin@tbnliving.com'],
            [
                'name' => 'Administrador Panel',
                'document_id' => 'ADMIN-TBN-001',
                'phone' => '70000001',
                'birth_date' => '1985-01-10',
                'mlm_role' => 'admin',
                'rank_id' => $rankId,
                'email_verified_at' => now(),
                'activation_paid_at' => now(),
                'account_status' => 'active',
                'country_id' => $boliviaId,
                'country_code' => 'BO',
                'password' => 'password',
            ]
        );
    }
}
