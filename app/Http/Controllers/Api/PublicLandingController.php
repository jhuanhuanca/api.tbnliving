<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class PublicLandingController extends Controller
{
    public function show(Request $request, string $memberCode)
    {
        $user = User::query()
            ->where('member_code', $memberCode)
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Landing no encontrada.'], 404);
        }

        $meta = is_array($user->meta) ? $user->meta : [];

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'member_code' => $user->member_code,
            ],
            'landing' => $meta['landing'] ?? null,
        ]);
    }
}

