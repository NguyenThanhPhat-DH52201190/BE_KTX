<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AutoRoomAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutoRoomAssignmentController extends Controller
{
    public function __construct(private AutoRoomAssignmentService $service) {}

    /**
     * POST /admin/rooms/auto-assign
     * Runs auto assignment for all eligible registrations.
     * Optionally scoped to a registration_period_id.
     */
    public function autoAssign(Request $request): JsonResponse
    {
        $periodId = $request->integer('registration_period_id') ?: null;

        $result = $this->service->run($periodId);

        return response()->json([
            'message'  => "Da phan phong tu dong: {$result['assigned']} sinh vien duoc de xuat, {$result['no_room']} khong co phong phu hop.",
            'assigned' => $result['assigned'],
            'no_room'  => $result['no_room'],
            'details'  => $result['details'],
        ]);
    }

    /**
     * POST /admin/rooms/confirm-proposals
     * Confirms all PROPOSED occupancies → ROOM_CONFIRMED.
     */
    public function confirmProposals(Request $request): JsonResponse
    {
        $periodId = $request->integer('registration_period_id') ?: null;

        $result = $this->service->confirmProposals($periodId);

        return response()->json([
            'message'       => "Da xac nhan {$result['confirmed']} phong de xuat.",
            'confirmed'     => $result['confirmed'],
            'notifications' => $result['notifications'],
        ]);
    }
}