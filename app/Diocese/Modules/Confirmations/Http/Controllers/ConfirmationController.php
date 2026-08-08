<?php

namespace App\Diocese\Modules\Confirmations\Http\Controllers;

use App\Diocese\Modules\Confirmations\Http\Requests\StoreConfirmationRequest;
use App\Diocese\Modules\Confirmations\Http\Resources\ConfirmationResource;
use App\Diocese\Modules\Confirmations\Models\Confirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfirmationController
{
    /**
     * GET /api/confirmations
     *
     * Paginated confirmation ledger. Roles that can manage confirmations
     * (super_admin, pastor, secretary) see every record.
     */
    public function index(Request $request): JsonResponse
    {
        $records = Confirmation::with('member')
            ->orderByDesc('confirmed_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => ConfirmationResource::collection($records->items()),
            'meta' => [
                'total' => $records->total(),
                'per_page' => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/confirmations
     */
    public function store(StoreConfirmationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $record = Confirmation::create([
            'member_id' => $validated['member_id'],
            'recorded_by_user_id' => $request->user()->id,
            'confirmed_at' => $validated['confirmed_at'],
            'officiating_clergy' => $validated['officiating_clergy'] ?? null,
            'location' => $validated['location'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Confirmation recorded.',
            'data' => new ConfirmationResource($record->load('member')),
        ], 201);
    }

    /**
     * DELETE /api/confirmations/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['super_admin', 'pastor', 'secretary'])) {
            return response()->json(['message' => 'You are not allowed to delete confirmations.'], 403);
        }

        Confirmation::findOrFail($id)->delete();

        return response()->json(['message' => 'Confirmation deleted.']);
    }
}
