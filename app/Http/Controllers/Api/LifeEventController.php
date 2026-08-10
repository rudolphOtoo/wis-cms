<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LifeEvent\StoreLifeEventRequest;
use App\Http\Requests\LifeEvent\UpdateLifeEventRequest;
use App\Http\Resources\LifeEventResource;
use App\Models\LifeEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LifeEventController extends Controller
{
    /**
     * GET /api/life-events
     *
     * List recorded deaths and births. Branch scoping handled by the
     * BelongsToBranch trait on LifeEvent.
     *
     * Query params:
     *   type       (optional, 'death'|'birth')
     *   year       (optional, filter by event_date year)
     *   member_id  (optional, filter to a single member)
     *   search     (optional, name search)
     *   per_page   (optional, default 20)
     */
    public function index(Request $request): JsonResponse
    {
        $query = LifeEvent::query()
            ->with(['member', 'fatherMember', 'recorder']);

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        if ($year = $request->get('year')) {
            $query->whereYear('event_date', (int) $year);
        }

        if ($memberId = $request->get('member_id')) {
            $query->where('member_id', $memberId);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('mother_first_name', 'ilike', "%{$search}%")
                    ->orWhereHas('member', function ($m) use ($search) {
                        $m->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('fatherMember', function ($m) use ($search) {
                        $m->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%");
                    });
            });
        }

        $events = $query
            ->orderByDesc('event_date')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => LifeEventResource::collection($events->items()),
            'meta' => [
                'total' => $events->total(),
                'per_page' => $events->perPage(),
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/life-events
     *
     * Recording a death atomically marks the linked member as 'deceased'
     * and sets their date_of_death inside the same DB transaction.
     */
    public function store(StoreLifeEventRequest $request): JsonResponse
    {
        $event = DB::transaction(function () use ($request) {
            $event = LifeEvent::create([
                ...$request->validated(),
                'branch_id' => $request->user()->branch_id,
                'recorded_by_user_id' => $request->user()->id,
            ]);

            if ($event->type === 'death' && $event->member_id) {
                $event->member->update([
                    'status' => 'deceased',
                    'date_of_death' => $event->event_date,
                ]);
            }

            return $event;
        });

        activity()->causedBy($request->user())
            ->performedOn($event)
            ->log("Recorded {$event->type} life event for {$this->subjectName($event)}");

        return response()->json([
            'message' => $event->type === 'death'
                ? 'Death recorded successfully.'
                : 'Birth recorded successfully.',
            'data' => new LifeEventResource($event->load(['member', 'fatherMember', 'recorder'])),
        ], 201);
    }

    /**
     * GET /api/life-events/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $event = LifeEvent::with(['member', 'fatherMember', 'recorder'])->findOrFail($id);

        return response()->json(['data' => new LifeEventResource($event)]);
    }

    /**
     * PUT /api/life-events/{id}
     *
     * Updating a death re-syncs the linked member's status and
     * date_of_death so the register stays consistent.
     */
    public function update(UpdateLifeEventRequest $request, string $id): JsonResponse
    {
        $event = LifeEvent::findOrFail($id);

        $event->update($request->validated());

        if ($event->type === 'death' && $event->member_id) {
            $event->member?->update([
                'status' => 'deceased',
                'date_of_death' => $event->event_date,
            ]);
        }

        activity()->causedBy($request->user())
            ->performedOn($event)
            ->log("Updated {$event->type} life event for {$this->subjectName($event)}");

        return response()->json([
            'message' => 'Life event updated successfully.',
            'data' => new LifeEventResource($event->load(['member', 'fatherMember', 'recorder'])),
        ]);
    }

    /**
     * DELETE /api/life-events/{id}
     *
     * Soft delete. The linked member's status is NOT reverted — restore
     * the member manually if a death record was entered in error.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $event = LifeEvent::findOrFail($id);

        $name = $this->subjectName($event);
        $event->delete();

        activity()->causedBy($request->user())
            ->log("Removed {$event->type} life event for {$name}");

        return response()->json(['message' => 'Life event removed successfully.']);
    }

    /**
     * GET /api/life-events/stats?year=2026
     *
     * Totals for the given year (defaults to the current year) plus a
     * month-by-month deaths/births breakdown.
     */
    public function stats(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        $totals = LifeEvent::query()
            ->whereYear('event_date', $year)
            ->selectRaw("
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE type = 'death') AS deaths,
                COUNT(*) FILTER (WHERE type = 'birth') AS births
            ")
            ->first();

        $byMonth = LifeEvent::query()
            ->whereYear('event_date', $year)
            ->selectRaw("TO_CHAR(event_date, 'YYYY-MM') AS month, type, COUNT(*) AS count")
            ->groupBy('month', 'type')
            ->get()
            ->groupBy('month')
            ->mapWithKeys(function ($rows, $month) {
                $deaths = (int) $rows->where('type', 'death')->first()?->count;
                $births = (int) $rows->where('type', 'birth')->first()?->count;

                return [$month => ['deaths' => $deaths, 'births' => $births]];
            })
            ->sortKeys();

        return response()->json([
            'data' => [
                'year' => $year,
                'total' => (int) $totals->total,
                'deaths' => (int) $totals->deaths,
                'births' => (int) $totals->births,
                'by_month' => $byMonth,
            ],
        ]);
    }

    private function subjectName(LifeEvent $event): string
    {
        $name = trim("{$event->first_name} {$event->last_name}");

        if ($name) {
            return $name;
        }

        return $event->member?->full_name ?? 'member';
    }
}
