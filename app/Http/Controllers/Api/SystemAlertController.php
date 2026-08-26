<?php

namespace App\Http\Controllers\Api;

use App\Models\SystemAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SystemAlertController extends Controller
{
    /**
     * List system alerts (unread first, most recent first).
     *
     * GET /api/system-alerts?type=credit_depletion&unread=1&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $query = SystemAlert::query()->orderByDesc('created_at');

        if ($request->boolean('unread')) {
            $query->unread();
        }

        if ($request->filled('type')) {
            $query->ofType($request->input('type'));
        }

        $perPage = min((int) $request->input('per_page', 20), 50);
        $alerts = $query->paginate($perPage);

        return response()->json($alerts);
    }

    /**
     * Acknowledge a system alert.
     *
     * POST /api/system-alerts/{id}/acknowledge
     */
    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $alert = SystemAlert::findOrFail($id);

        $alert->acknowledge($request->user()?->id);

        return response()->json(['ok' => true]);
    }
}
