<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PastoralNote\StorePastoralNoteRequest;
use App\Http\Requests\PastoralNote\UpdatePastoralNoteRequest;
use App\Http\Resources\PastoralNoteResource;
use App\Models\Cell;
use App\Models\PastoralNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PastoralNoteController extends Controller
{
    /**
     * GET /api/pastoral-notes
     *
     * List pastoral notes. Cell leaders see notes for their cell members.
     * Pastors/admins see all notes.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PastoralNote::with(['member', 'author'])
            ->orderByDesc('created_at');

        // Cell leaders are scoped to their own cells
        if ($request->user()->hasRole('cell_leader')) {
            $cellIds = Cell::where('leader_user_id', $request->user()->id)
                ->pluck('id')
                ->toArray();
            $query->whereHas('member', fn ($q) => $q->whereIn('cell_id', $cellIds));
        }

        // Optional filters
        if ($request->filled('member_id')) {
            $query->where('member_id', $request->input('member_id'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        $notes = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => PastoralNoteResource::collection($notes->items()),
            'meta' => [
                'total' => $notes->total(),
                'per_page' => $notes->perPage(),
                'current_page' => $notes->currentPage(),
                'last_page' => $notes->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/pastoral-notes
     */
    public function store(StorePastoralNoteRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $note = PastoralNote::create([
            'member_id' => $validated['member_id'],
            'author_user_id' => $request->user()->id,
            'branch_id' => $request->user()->branch_id,
            'category' => $validated['category'] ?? 'general',
            'title' => $validated['title'],
            'body' => $validated['body'],
            'follow_up_required' => $validated['follow_up_required'] ?? false,
            'follow_up_date' => $validated['follow_up_date'] ?? null,
        ]);

        return response()->json([
            'message' => 'Pastoral note created.',
            'data' => new PastoralNoteResource($note->load(['member', 'author'])),
        ], 201);
    }

    /**
     * PUT /api/pastoral-notes/{id}
     */
    public function update(UpdatePastoralNoteRequest $request, string $id): JsonResponse
    {
        $note = PastoralNote::findOrFail($id);
        $this->authorize('update', $note);

        $note->update($request->validated());

        return response()->json([
            'message' => 'Pastoral note updated.',
            'data' => new PastoralNoteResource($note->load(['member', 'author'])),
        ]);
    }

    /**
     * DELETE /api/pastoral-notes/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $note = PastoralNote::findOrFail($id);
        $this->authorize('delete', $note);

        $note->delete();

        return response()->json(['message' => 'Pastoral note deleted.']);
    }

    /**
     * GET /api/pastoral-notes/follow-ups
     *
     * Returns pending follow-ups for pastoral care.
     * Cell leaders see follow-ups for their cell members.
     * Pastors/admins see all pending follow-ups.
     */
    public function followUps(Request $request): JsonResponse
    {
        $query = PastoralNote::with(['member', 'author'])
            ->where('follow_up_required', true)
            ->where('follow_up_completed', false)
            ->orderBy('follow_up_date');

        // Cell leaders are scoped to their own cells
        if ($request->user()->hasRole('cell_leader')) {
            $cellIds = Cell::where('leader_user_id', $request->user()->id)
                ->pluck('id')
                ->toArray();
            $query->whereHas('member', fn ($q) => $q->whereIn('cell_id', $cellIds));
        }

        $notes = $query->get();

        return response()->json([
            'data' => PastoralNoteResource::collection($notes),
        ]);
    }
}
