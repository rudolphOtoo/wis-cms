<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::query()->with('causer');

        if ($search = $request->get('search')) {
            $query->where('description', 'ilike', "%{$search}%");
        }

        if ($causerId = $request->get('user_id')) {
            $query->where('causer_id', $causerId);
        }

        if ($from = $request->get('from')) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to = $request->get('to')) {
            $query->where('created_at', '<', Carbon::parse($to)->addDay()->startOfDay());
        }

        if ($subjectType = $request->get('subject_type')) {
            $query->where('subject_type', 'like', "%{$subjectType}%");
        }

        $activities = $query
            ->latest()
            ->paginate($request->get('per_page', 25));

        return response()->json([
            'data' => collect($activities->items())->map(fn ($a) => [
                'id' => $a->id,
                'description' => $a->description,
                'subject_type' => $a->subject_type ? class_basename($a->subject_type) : null,
                'subject_id' => $a->subject_id,
                'causer' => $a->causer ? [
                    'id' => $a->causer->id,
                    'name' => $a->causer->name,
                ] : null,
                'created_at' => $a->created_at->format('Y-m-d H:i:s'),
                'when' => $a->created_at->diffForHumans(),
            ]),
            'meta' => [
                'total' => $activities->total(),
                'per_page' => $activities->perPage(),
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
            ],
        ]);
    }
}
