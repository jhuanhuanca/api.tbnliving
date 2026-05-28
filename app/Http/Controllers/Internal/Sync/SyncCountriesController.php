<?php

namespace App\Http\Controllers\Internal\Sync;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncCountriesController extends BaseSyncController
{
    public function index(Request $request)
    {
        // Core no tiene tabla countries; usamos country_code de users como fuente.
        $limit = $this->parseLimit($request, 500, 2000);
        $cursor = $request->query('cursor');

        // Cursor sobre "country_code" como string: para simplicidad, usamos paginado por id de user y agregamos.
        // Para enterprise real: exponer una tabla countries en el core o un catálogo central.
        $q = User::query()
            ->select(['id', 'country_code', 'updated_at'])
            ->whereNotNull('country_code')
            ->orderBy('updated_at')
            ->orderBy('id');

        $this->applyCursor($q, is_string($cursor) ? $cursor : null);

        $rows = $q->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $slice = $rows->take($limit);
        $last = $slice->last();

        $codes = $slice->pluck('country_code')->filter()->map(fn ($c) => strtoupper((string) $c))->unique()->values();

        return response()->json([
            'entity' => 'countries',
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->nextCursorFromRow($last) : null,
            'data' => $codes->map(fn ($c) => ['country_code' => $c])->values(),
        ]);
    }
}

