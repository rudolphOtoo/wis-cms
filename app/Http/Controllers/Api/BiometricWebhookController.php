<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\RecordBiometricAttendanceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Biometric\RecordBiometricScanRequest;
use Illuminate\Http\JsonResponse;

/**
 * Public ingest endpoint for biometric entrance scanners.
 *
 * POST /api/biometric/scans
 *
 * Returns 202 the moment the scan is queued (never waits for the DB write),
 * so the terminal can display the member's name and open the door without
 * blocking the queue. Duplicate scans within the dedupe window return 200.
 */
class BiometricWebhookController extends Controller
{
    public function __construct(private readonly RecordBiometricAttendanceAction $action) {}

    public function store(RecordBiometricScanRequest $request): JsonResponse
    {
        $result = $this->action->execute($request->validated());

        return match ($result['status']) {
            'recorded' => response()->json([
                'status' => 'recorded',
                'message' => 'Attendance recorded.',
                'member' => $this->memberPayload($result['member']),
            ], 202),

            'duplicate' => response()->json([
                'status' => 'duplicate',
                'message' => 'Attendance already recorded.',
                'member' => $this->memberPayload($result['member']),
            ], 200),

            default => response()->json([
                'status' => 'unknown',
                'message' => 'Fingerprint not recognised.',
                'member' => null,
            ], 200),
        };
    }

    /**
     * @param  array<string, mixed>|null  $member
     * @return array<string, mixed>|null
     */
    protected function memberPayload(?array $member): ?array
    {
        if ($member === null) {
            return null;
        }

        return [
            'id' => $member['member_id'],
            'name' => $member['member_name'],
            'cell_id' => $member['cell_id'],
            'cell_name' => $member['cell_name'],
        ];
    }
}
