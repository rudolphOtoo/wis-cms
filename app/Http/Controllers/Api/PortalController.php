<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    /**
     * Resolve the authenticated user's OWN member record.
     *
     * SECURITY: never accepts an ID from the request. The member_id
     * comes only from the authenticated user's session. A member cannot
     * ask for anyone else's data because there is no parameter to change.
     */
    protected function currentMember(Request $request): Member
    {
        $memberId = $request->user()->member_id;
        abort_if(! $memberId, 403, 'Your account is not linked to a member profile.');

        return Member::findOrFail($memberId);
    }

    public function profile(Request $request): JsonResponse
    {
        $member = $this->currentMember($request);

        return response()->json([
            'data' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_number' => $member->member_number,
                'gender' => $member->gender,
                'phone' => $member->phone,
                'email' => $member->email,
                'address' => $member->address,
                'occupation' => $member->occupation,
                'marital_status' => $member->marital_status,
                'date_of_birth' => $member->date_of_birth?->format('Y-m-d'),
                'join_date' => $member->join_date?->format('Y-m-d'),
                'is_baptised' => $member->is_baptised,
                'status' => $member->status,
            ],
        ]);
    }

    /**
     * GET /portal/giving
     *
     * HIGH-02 FIX: `year` is now validated as an integer within a
     * sensible range before being used in date queries. The previous
     * `$request->get('year', ...)` accepted arbitrary strings without
     * any bounds check.
     */
    public function giving(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $member = $this->currentMember($request);
        $year = $request->integer('year', (int) now()->format('Y'));

        $transactions = $member->transactions()
            ->where('type', 'income')
            ->whereYear('transaction_date', $year)
            ->with('category')
            ->orderByDesc('transaction_date')
            ->get();

        $byCategory = $transactions
            ->groupBy(fn ($t) => $t->category?->name ?? 'Uncategorised')
            ->map(fn ($group) => [
                'category' => $group->first()->category?->name ?? 'Uncategorised',
                'count' => $group->count(),
                'total' => round($group->sum('amount'), 2),
            ])->values();

        $availableYears = $member->transactions()
            ->where('type', 'income')
            ->selectRaw('EXTRACT(YEAR FROM transaction_date) as yr')
            ->distinct()->orderByDesc('yr')
            ->pluck('yr')->map(fn ($y) => (int) $y);

        return response()->json([
            'data' => [
                'year' => $year,
                'available_years' => $availableYears,
                'total' => round($transactions->sum('amount'), 2),
                'by_category' => $byCategory,
                'transactions' => $transactions->map(fn ($t) => [
                    'id' => $t->id,
                    'date' => $t->transaction_date->format('Y-m-d'),
                    'category' => $t->category?->name ?? 'Uncategorised',
                    'amount' => round($t->amount, 2),
                    'reference' => $t->reference,
                ]),
            ],
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $member = $this->currentMember($request);

        $records = AttendanceRecord::query()
            ->where('member_id', $member->id)
            ->where('is_present', true)
            ->with('session.serviceType')
            ->get()
            ->sortByDesc(fn ($r) => $r->session?->service_date)
            ->take(50)
            ->values();

        $totalPresent = AttendanceRecord::where('member_id', $member->id)
            ->where('is_present', true)
            ->count();

        return response()->json([
            'data' => [
                'total_present' => $totalPresent,
                'recent' => $records->map(fn ($r) => [
                    'id' => $r->id,
                    'date' => $r->session?->service_date?->format('Y-m-d'),
                    'service' => $r->session?->serviceType?->name ?? 'Service',
                ]),
            ],
        ]);
    }
}
