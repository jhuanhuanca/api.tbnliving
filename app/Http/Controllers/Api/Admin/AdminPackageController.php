<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPackageController extends Controller
{
    public function index()
    {
        $rows = Package::query()->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:packages,slug'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'pv_points' => ['required', 'numeric', 'min:0'],
            'commissionable_amount' => ['nullable', 'numeric', 'min:0'],
            'estado' => ['nullable', 'string', 'in:activo,inactivo'],
        ]);

        $data['estado'] = $data['estado'] ?? 'activo';

        $package = Package::query()->create($data);

        return response()->json($package, 201);
    }

    public function update(Request $request, Package $package)
    {
        $data = $request->validate([
            'slug' => [
                'sometimes',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('packages', 'slug')->ignore($package->id),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'pv_points' => ['sometimes', 'numeric', 'min:0'],
            'commissionable_amount' => ['nullable', 'numeric', 'min:0'],
            'estado' => ['sometimes', 'string', 'in:activo,inactivo'],
        ]);

        $package->update($data);

        return response()->json($package->fresh());
    }

    public function destroy(Package $package)
    {
        $package->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Paquete desactivado.'], 200);
    }
}
