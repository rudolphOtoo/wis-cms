<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Children\StoreChildrenRequest;
use App\Http\Requests\Children\UpdateChildrenRequest;
use App\Http\Resources\ChildrenResource;
use App\Models\Children;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChildrenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Branch scoping handled by BelongsToBranch trait on Children.
        $query = Children::query()
            ->with('guardian');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('class_group', 'ilike', "%{$search}%");
            });
        }

        if ($classGroup = $request->get('class_group')) {
            $query->where('class_group', $classGroup);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('cell_id')) {
            $cellId = $request->get('cell_id');
            $cellId === 'null' ? $query->whereNull('cell_id') : $query->where('cell_id', $cellId);
        }

        $children = $query
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => ChildrenResource::collection($children->items()),
            'meta' => [
                'total' => $children->total(),
                'per_page' => $children->perPage(),
                'current_page' => $children->currentPage(),
                'last_page' => $children->lastPage(),
            ],
        ]);
    }

    public function store(StoreChildrenRequest $request): JsonResponse
    {
        $child = Children::create([
            ...$request->validated(),
            'branch_id' => $request->user()->branch_id,
            'is_active' => $request->boolean('is_active', true),
        ]);

        activity()->causedBy($request->user())
            ->performedOn($child)
            ->log("Registered child: {$child->full_name}");

        return response()->json([
            'message' => 'Child registered successfully.',
            'data' => new ChildrenResource($child->load('guardian')),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        // Branch scoping handled by BelongsToBranch trait on Children.
        $child = Children::with('guardian')->findOrFail($id);

        return response()->json(['data' => new ChildrenResource($child)]);
    }

    public function update(UpdateChildrenRequest $request, string $id): JsonResponse
    {
        // Branch scoping handled by BelongsToBranch trait on Children.
        $child = Children::findOrFail($id);

        $child->update($request->validated());

        activity()->causedBy($request->user())
            ->performedOn($child)
            ->log("Updated child: {$child->full_name}");

        return response()->json([
            'message' => 'Child updated successfully.',
            'data' => new ChildrenResource($child->load('guardian')),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        // Branch scoping handled by BelongsToBranch trait on Children.
        $child = Children::findOrFail($id);

        $name = $child->full_name;
        $child->delete();

        activity()->causedBy($request->user())
            ->log("Removed child: {$name}");

        return response()->json(['message' => 'Child removed successfully.']);
    }

    public function stats(Request $request): JsonResponse
    {
        $stats = Children::query()
            ->selectRaw("
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE is_active = true) AS active,
                COUNT(*) FILTER (WHERE gender = 'male') AS male,
                COUNT(*) FILTER (WHERE gender = 'female') AS female
            ")
            ->first();

        $byClass = Children::query()
            ->selectRaw('class_group, COUNT(*) AS count')
            ->whereNotNull('class_group')
            ->groupBy('class_group')
            ->pluck('count', 'class_group');

        return response()->json([
            'data' => [
                'total' => $stats->total,
                'active' => $stats->active,
                'male' => $stats->male,
                'female' => $stats->female,
                'by_class' => $byClass,
            ],
        ]);
    }
}
