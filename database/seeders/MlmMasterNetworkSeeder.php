<?php

namespace Database\Seeders;

use App\Models\BinaryPlacement;
use App\Models\Country;
use App\Models\Rank;
use App\Models\User;
use App\Services\MemberCodeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * MASTER (código 1) + 36 espacios reservados en binario (18 izquierda, 18 derecha).
 *
 * Ejecutar: php artisan db:seed --class=MlmMasterNetworkSeeder
 */
class MlmMasterNetworkSeeder extends Seeder
{
    public const MASTER_MEMBER_CODE = 1;

    public function run(): void
    {
        $reservedPerLeg = max(1, (int) config('mlm.master_network.reserved_per_leg', 18));
        $firstLeftCode = self::MASTER_MEMBER_CODE + 1;
        $firstRightCode = $firstLeftCode + $reservedPerLeg;
        $nextAssignable = $firstRightCode + $reservedPerLeg;

        /** @var int|null $boliviaId */
        $boliviaId = Country::query()->where('code', 'BO')->value('id');
        $rankId = Rank::query()->where('slug', 'diamante_corona')->value('id')
            ?? Rank::query()->where('slug', 'activo')->value('id');

        $master = User::query()->updateOrCreate(
            ['member_code' => self::MASTER_MEMBER_CODE],
            [
                'name' => 'Oscar Orellana Aguilar',
                'email' => 'oscar.orellana@tbnliving.com',
                'password' => Hash::make('12345678'),
                'document_id' => '3850408 S.C.',
                'phone' => '71885588',
                'birth_date' => '1970-01-01',
                'referral_code' => MemberCodeService::formatReferralCode(self::MASTER_MEMBER_CODE),
                'sponsor_id' => null,
                'mlm_role' => 'superadmin',
                'account_type' => 'member',
                'account_status' => 'active',
                'is_mlm_qualified' => true,
                'rank_id' => $rankId,
                'country_id' => $boliviaId,
                'country_code' => 'BO',
                'email_verified_at' => now(),
                'activation_paid_at' => now(),
                'last_mlm_activity_at' => now(),
                'meta' => [
                    'is_master' => true,
                    'seeded_by' => 'MlmMasterNetworkSeeder',
                ],
            ]
        );

        $this->seedReservedLegChain(
            master: $master,
            startCode: $firstLeftCode,
            count: $reservedPerLeg,
            leg: BinaryPlacement::LEG_LEFT,
            label: 'izquierda',
        );

        $this->seedReservedLegChain(
            master: $master,
            startCode: $firstRightCode,
            count: $reservedPerLeg,
            leg: BinaryPlacement::LEG_RIGHT,
            label: 'derecha',
        );

        app(MemberCodeService::class)->syncCounter($nextAssignable);

        $this->command?->info(sprintf(
            'MASTER %s (%s) + %d reservados por pierna. Próximo código libre: %s.',
            $master->name,
            $master->referral_code,
            $reservedPerLeg,
            MemberCodeService::formatReferralCode($nextAssignable)
        ));
    }

    protected function seedReservedLegChain(
        User $master,
        int $startCode,
        int $count,
        string $leg,
        string $label,
    ): void {
        $parentUserId = $master->id;

        for ($i = 0; $i < $count; $i++) {
            $code = $startCode + $i;
            $referral = MemberCodeService::formatReferralCode($code);

            $user = User::query()->updateOrCreate(
                ['member_code' => $code],
                [
                    'name' => "Reservado pierna {$label} #{$referral}",
                    'email' => "reservado-{$referral}@internal.tbnliving.local",
                    'password' => Hash::make(Str::random(32)),
                    'document_id' => "RESERVED-{$referral}",
                    'phone' => null,
                    'birth_date' => null,
                    'referral_code' => $referral,
                    'sponsor_id' => $master->id,
                    'mlm_role' => 'member',
                    'account_type' => 'member',
                    'account_status' => 'reserved',
                    'is_mlm_qualified' => false,
                    'rank_id' => Rank::query()->where('slug', 'sin_rango')->value('id'),
                    'country_code' => 'BO',
                    'email_verified_at' => null,
                    'activation_paid_at' => null,
                    'meta' => [
                        'reserved_binary_slot' => true,
                        'reserved_leg' => $leg,
                        'reserved_index' => $i + 1,
                        'seeded_by' => 'MlmMasterNetworkSeeder',
                    ],
                ]
            );

            BinaryPlacement::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'parent_user_id' => $parentUserId,
                    'leg_position' => $leg,
                ]
            );

            $parentUserId = $user->id;
        }
    }
}
