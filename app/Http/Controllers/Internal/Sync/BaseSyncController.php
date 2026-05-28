<?php

namespace App\Http\Controllers\Internal\Sync;

use App\Http\Controllers\Controller;
use App\Support\SyncCursor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class BaseSyncController extends Controller
{
    protected function parseLimit(Request $request, int $default = 1000, int $max = 5000): int
    {
        $limit = (int) ($request->query('limit', $default));
        if ($limit <= 0) {
            $limit = $default;
        }
        if ($limit > $max) {
            $limit = $max;
        }
        return $limit;
    }

    protected function parseUpdatedSince(Request $request): ?string
    {
        $raw = (string) $request->query('updated_since', '');
        if (trim($raw) === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function applyCursor(Builder $q, ?string $cursor): void
    {
        $c = SyncCursor::decode($cursor);
        if (! $c) {
            return;
        }

        $q->where(function ($w) use ($c) {
            $w->where('updated_at', '>', $c['updated_at'])
                ->orWhere(function ($w2) use ($c) {
                    $w2->where('updated_at', '=', $c['updated_at'])->where('id', '>', $c['id']);
                });
        });
    }

    protected function nextCursorFromRow($row): ?string
    {
        if (! $row || ! isset($row->updated_at) || ! isset($row->id)) {
            return null;
        }
        $iso = $row->updated_at instanceof \DateTimeInterface ? Carbon::parse($row->updated_at)->toISOString() : Carbon::parse((string) $row->updated_at)->toISOString();
        return SyncCursor::encode($iso, (int) $row->id);
    }
}

