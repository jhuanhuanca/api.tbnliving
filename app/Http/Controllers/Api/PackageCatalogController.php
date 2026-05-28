<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
class PackageCatalogController extends Controller
{
    public function index()
    {
        $packages = Package::query()
            ->where('estado', 'activo')
            ->orderBy('price')
            ->get(['id', 'slug', 'name', 'price', 'pv_points', 'commissionable_amount']);

        return response()->json(['data' => $packages]);
    }
}
