<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Cursor estable para incremental sync (orden: updated_at asc, id asc).
 *
 * Cursor = base64url(json):
 * {
 *   "updated_at": "2026-05-01T10:00:00Z",
 *   "id": 123
 * }
 */
class SyncCursor
{
    public static function decode(?string $cursor): ?array
    {
        $c = trim((string) $cursor);
        if ($c === '') {
            return null;
        }

        $c = strtr($c, '-_', '+/');
        $pad = strlen($c) % 4;
        if ($pad !== 0) {
            $c .= str_repeat('=', 4 - $pad);
        }

        $raw = base64_decode($c, true);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }

        $updatedAt = $data['updated_at'] ?? null;
        $id = $data['id'] ?? null;
        if (! is_string($updatedAt) || $updatedAt === '' || ! is_numeric($id)) {
            return null;
        }

        try {
            $dt = Carbon::parse($updatedAt)->toISOString();
        } catch (\Throwable) {
            return null;
        }

        return ['updated_at' => $dt, 'id' => (int) $id];
    }

    public static function encode(string $updatedAtIso, int $id): string
    {
        $json = json_encode(['updated_at' => $updatedAtIso, 'id' => $id], JSON_UNESCAPED_SLASHES);
        $b64 = base64_encode($json ?: '{}');
        return rtrim(strtr($b64, '+/', '-_'), '=');
    }
}

