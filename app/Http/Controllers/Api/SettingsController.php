<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch-level settings (follow-up SMS templates, delay window, enable toggle).
 *
 * Reads/writes the user's own branch row. WIS is single-branch today,
 * but the per-branch shape lets each future branch have its own copy
 * (e.g. English vs Twi templates).
 *
 * Permission: 'manage users' (the same admins who manage users also
 * configure system settings). Promote to a dedicated 'manage settings'
 * permission only when the use cases actually diverge.
 */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $branch = $request->user()->branch;

        return response()->json([
            'data' => [
                'follow_up_enabled' => (bool) $branch->follow_up_enabled,
                'follow_up_delay_hours' => (int) $branch->follow_up_delay_hours,
                'follow_up_present_template' => $branch->follow_up_present_template,
                'follow_up_absent_template' => $branch->follow_up_absent_template,
            ],
        ]);
    }

    public function updateFollowUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'follow_up_enabled' => ['required', 'boolean'],
            'follow_up_delay_hours' => ['required', 'integer', 'between:1,24'],
            'follow_up_present_template' => ['required', 'string', 'max:1000'],
            'follow_up_absent_template' => ['required', 'string', 'max:1000'],
        ]);

        $branch = $request->user()->branch;
        $branch->update($data);

        activity()->causedBy($request->user())
            ->performedOn($branch)
            ->log('Updated post-meeting follow-up SMS settings');

        return response()->json([
            'message' => 'Follow-up settings updated.',
            'data' => [
                'follow_up_enabled' => (bool) $branch->follow_up_enabled,
                'follow_up_delay_hours' => (int) $branch->follow_up_delay_hours,
                'follow_up_present_template' => $branch->follow_up_present_template,
                'follow_up_absent_template' => $branch->follow_up_absent_template,
            ],
        ]);
    }
}
