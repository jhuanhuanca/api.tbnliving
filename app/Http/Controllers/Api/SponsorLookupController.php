<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MemberCodeService;
class SponsorLookupController extends Controller
{
    public function show(string $code)
    {
        $user = MemberCodeService::findUserBySponsorCode($code);
        if (! $user) {
            return response()->json([
                'valid' => false,
                'message' => 'Código de patrocinador no encontrado.',
            ], 404);
        }

        return response()->json([
            'valid' => true,
            'sponsor' => [
                'referral_code' => $user->referral_code,
                'member_code' => $user->member_code,
                'name' => $user->name,
            ],
        ]);
    }
}
