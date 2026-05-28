<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MlmBonusCalculatorService;
use Illuminate\Http\Request;

class MlmBonusCalculatorController extends Controller
{
    public function calculate(Request $request, MlmBonusCalculatorService $calc)
    {
        $data = $request->validate([
            'binary' => 'nullable|array',
            'binary.leftPV' => 'nullable|array',
            'binary.rightPV' => 'nullable|array',
            'binary.rate' => 'nullable',
            'binary.weeklyCap' => 'nullable',
            'binary.monthPenalty' => 'nullable',

            'leadership' => 'nullable|array',
            'leadership.monthlyTeamPV' => 'nullable|array',
            'leadership.requiredPV' => 'nullable',
            'leadership.leadershipRate' => 'nullable',
            'leadership.months' => 'nullable|integer|min:1|max:12',
        ]);

        $out = [];

        if (isset($data['binary'])) {
            $b = $data['binary'];
            $out['binary'] = $calc->calcularBonoBinario(
                $b['leftPV'] ?? [],
                $b['rightPV'] ?? [],
                (string) ($b['rate'] ?? config('mlm.binary.hybrid_daily.rate', '0.21')),
                // En la calculadora pura dejamos el cap como "unidad de la fórmula" (típicamente USD),
                // por eso el default viene de weekly_cap_usd (no del cap en BOB del motor real).
                (string) ($b['weeklyCap'] ?? config('mlm.binary.hybrid_daily.weekly_cap_usd', '2500')),
                (string) ($b['monthPenalty'] ?? config('mlm.binary.hybrid_daily.month_penalty', '0.10'))
            );
        }

        if (isset($data['leadership'])) {
            $l = $data['leadership'];
            $out['leadership'] = $calc->calcularBonoLiderazgo(
                $l['monthlyTeamPV'] ?? [],
                (string) ($l['requiredPV'] ?? '3600'),
                (string) ($l['leadershipRate'] ?? '0.10'),
                (int) ($l['months'] ?? 3)
            );
        }

        return response()->json([
            'ok' => true,
            'results' => $out,
        ]);
    }
}

