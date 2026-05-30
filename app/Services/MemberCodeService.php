<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class MemberCodeService
{
    public static function formatReferralCode(int $code): string
    {
        $width = max(1, (int) config('mlm.member_code.pad_width', 6));

        return str_pad((string) $code, $width, '0', STR_PAD_LEFT);
    }

    public function assignToNewUser(User $user): void
    {
        if ($user->member_code !== null && $user->referral_code !== null) {
            return;
        }

        $min = (int) config('mlm.member_code.min', 1);
        $max = (int) config('mlm.member_code.max', 1_000_000);

        DB::transaction(function () use ($user, $min, $max) {
            $row = DB::table('mlm_member_code_counter')->lockForUpdate()->first();
            if (! $row) {
                DB::table('mlm_member_code_counter')->insert(['next_assignable' => $min + 1]);
                $assigned = $min;
            } else {
                $assigned = (int) $row->next_assignable;
                if ($assigned > $max) {
                    throw new \RuntimeException('Se alcanzó el límite de códigos de socio ('.$max.').');
                }
                DB::table('mlm_member_code_counter')
                    ->where('id', $row->id)
                    ->update(['next_assignable' => $assigned + 1]);
            }

            $user->member_code = $assigned;
            $user->referral_code = self::formatReferralCode($assigned);
        });
    }

    public function syncCounter(int $nextAssignable): void
    {
        DB::table('mlm_member_code_counter')->updateOrInsert(
            ['id' => 1],
            ['next_assignable' => max(1, $nextAssignable)]
        );
    }

    public static function findUserBySponsorCode(string $code): ?User
    {
        $c = trim($code);
        if ($c === '') {
            return null;
        }

        $eligible = static fn () => User::query()->where('account_status', '!=', 'reserved');

        $user = $eligible()->where('referral_code', $c)->first();
        if ($user) {
            return $user;
        }

        if (ctype_digit($c)) {
            $numeric = (int) $c;

            return $eligible()
                ->where(function ($q) use ($numeric, $c) {
                    $q->where('member_code', $numeric)
                        ->orWhere('referral_code', self::formatReferralCode($numeric))
                        ->orWhere('referral_code', $c);
                })
                ->first();
        }

        return null;
    }
}
