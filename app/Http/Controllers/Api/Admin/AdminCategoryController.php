<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;

class AdminCategoryController extends Controller
{
    public function index()
    {
        $rows = Category::query()->orderBy('name')->get(['id', 'name', 'slug']);

        return response()->json(['data' => $rows]);
    }
}
