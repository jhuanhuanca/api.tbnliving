<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\LeadershipService;

class AdminLeadershipController extends Controller
{
    public function show(string $monthKey, LeadershipService $leadershipService)
    {
        return response()->json($leadershipService->monthlySummary($monthKey));
    }
}
