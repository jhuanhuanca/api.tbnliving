<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;

class PublicCountryController extends Controller
{
    public function index(Request $request)
    {
        $q = Country::query()
            ->whereNotNull('code')
            ->orderBy('name');

        if ($request->filled('codes')) {
            $codes = array_filter(array_map('trim', explode(',', (string) $request->query('codes'))));
            if ($codes !== []) {
                $q->whereIn('code', array_map('strtoupper', $codes));
            }
        }

        return response()->json([
            'data' => $q->get(['id', 'code', 'name', 'flag_emoji'])->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'flag' => $c->flag_emoji,
            ])->values(),
        ]);
    }
}
