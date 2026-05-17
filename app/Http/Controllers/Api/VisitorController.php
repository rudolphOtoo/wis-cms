<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Visitor\StoreVisitorRequest;
use App\Http\Requests\Visitor\UpdateVisitorRequest;
use App\Http\Resources\VisitorResource;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Visitor::query()
            ->where('branch_id', $request->user()->branch_id)
            ->with('convertedMember');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name',  'ilike', "%{$search}%")
                  ->orWhere('phone',      'ilike', "%{$search}%");
            });
        }

        if ($status = $request->get('follow_up_status')) {
            $query->where('follow_up_status', $status);
        }

        $visitors = $query
            ->orderByDesc('visit_date')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => VisitorResource::collection($visitors->items()),
            'meta' => [
                'total'        => $visitors->total(),
                'per_page'     => $visitors->perPage(),
                'current_page' => $visitors->currentPage(),
                'last_page'    => $visitors->lastPage(),
            ],
        ]);
    }

    public function store(StoreVisitorRequest $request): JsonResponse
    {
        $visitor = Visitor::create([
            ...$request->validated(),
            'branch_id'        => $request->user()->branch_id,
            'follow_up_status' => $request->get('follow_up_status', 'pending'),
        ]);

        activity()->causedBy($request->user())
                  ->performedOn($visitor)
                  ->log("Registered new visitor: {$visitor->full_name}");

        return response()->json([
            'message' => 'Visitor registered successfully.',
            'data'    => new VisitorResource($visitor),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $visitor = Visitor::where('branch_id', $request->user()->branch_id)
                          ->with('convertedMember')
                          ->findOrFail($id);

        return response()->json(['data' => new VisitorResource($visitor)]);
    }

    public function update(UpdateVisitorRequest $request, string $id): JsonResponse
    {
        $visitor = Visitor::where('branch_id', $request->user()->branch_id)
                          ->findOrFail($id);

        $visitor->update($request->validated());

        activity()->causedBy($request->user())
                  ->performedOn($visitor)
                  ->log("Updated visitor: {$visitor->full_name}");

        return response()->json([
            'message' => 'Visitor updated successfully.',
            'data'    => new VisitorResource($visitor),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $visitor = Visitor::where('branch_id', $request->user()->branch_id)
                          ->findOrFail($id);

        $name = $visitor->full_name;
        $visitor->delete();

        activity()->causedBy($request->user())
                  ->log("Deleted visitor: {$name}");

        return response()->json(['message' => 'Visitor deleted successfully.']);
    }

    public function stats(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        return response()->json([
            'data' => [
                'total'         => Visitor::where('branch_id', $branchId)->count(),
                'pending'       => Visitor::where('branch_id', $branchId)->where('follow_up_status', 'pending')->count(),
                'contacted'     => Visitor::where('branch_id', $branchId)->where('follow_up_status', 'contacted')->count(),
                'joined'        => Visitor::where('branch_id', $branchId)->where('follow_up_status', 'joined')->count(),
                'this_month'    => Visitor::where('branch_id', $branchId)
                                          ->whereMonth('visit_date', now()->month)
                                          ->whereYear('visit_date',  now()->year)
                                          ->count(),
            ],
        ]);
    }
}
