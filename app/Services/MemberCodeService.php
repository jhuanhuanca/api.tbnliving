<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class MemberCodeService
{
    public function assignToNewUser(User $user): void
    {
        if ($user->member_code !== null && $user->referral_code !== null) {
            return;
        }

        $min = (int) config('mlm.member_code.min', 10);
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
            $user->referral_code = (string) $assigned;
        });
    }

    public static function findUserBySponsorCode(string $code): ?User
    {
        $c = trim($code);
        if ($c === '') {
            return null;
        }

        $user = User::query()->where('referral_code', $c)->first();
        if ($user) {
            return $user;
        }

        if (ctype_digit($c)) {
            return User::query()->where('member_code', (int) $c)->first();
        }

        return null;
    }
}
