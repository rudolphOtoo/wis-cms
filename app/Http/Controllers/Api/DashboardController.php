<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        return response()->json([
            'stats' => [
                'total_members'  => 0,
                'total_children' => 0,
                'total_visitors' => 0,
                'total_departments' => 0,
            ],
        ]);
    }
}
