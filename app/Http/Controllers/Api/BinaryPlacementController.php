<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BinaryPlacement;
use App\Services\BinaryService;
use Illuminate\Http\Request;

class BinaryPlacementController extends Controller
{
    public function store(Request $request, BinaryService $binaryService)
    {
        $this->authorize('create', BinaryPlacement::class);

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'parent_user_id' => 'nullable|exists:users,id',
            'leg_position' => 'nullable|in:left,right',
        ]);

        if (! empty($data['parent_user_id']) && empty($data['leg_position'])) {
            return response()->json(['message' => 'leg_position es obligatorio si hay padre'], 422);
        }

        if (! empty($data['parent_user_id'])) {
            $exists = BinaryPlacement::query()
                ->where('parent_user_id', $data['parent_user_id'])
                ->where('leg_position', $data['leg_position'])
                ->where('user_id', '!=', $data['user_id'])
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'La pierna ya está ocupada para ese padre'], 422);
            }
        }

        $placement = BinaryPlacement::query()->updateOrCreate(
            ['user_id' => $data['user_id']],
            [
                'parent_user_id' => $data['parent_user_id'] ?? null,
                'leg_position' => $data['leg_position'] ?? null,
            ]
        );

        $binaryService->olvidarCacheArbol((int) $data['user_id']);
        if (! empty($data['parent_user_id'])) {
            $binaryService->olvidarCacheArbol((int) $data['parent_user_id']);
        }

        return response()->json($placement, 201);
    }
}
