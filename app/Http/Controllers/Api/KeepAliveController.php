<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KeepAliveController extends Controller
{
    /**
     * Touch the session to prevent idle timeout. Called by the frontend
     * when the user clicks "Stay Logged In" in the idle warning modal.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->session()->put('last_activity_at', now()->timestamp);

        return response()->json([
            'session_lifetime' => (int) config('session.lifetime', 15),
        ]);
    }
}
