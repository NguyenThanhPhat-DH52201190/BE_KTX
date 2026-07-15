<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DormReservation;
use App\Models\PriorityCriteria;
use App\Models\ReservationPriority;
use App\Models\ReservationPriorityEvidence;
use App\Services\PriorityRankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReservationPriorityController extends Controller
{
    // =========================================================
    // Public routes — verified by reservation_code
    // =========================================================

    /**
     * Tân SV khai báo một tiêu chí ưu tiên cho hồ sơ giữ chỗ.
     * POST /dorm-reservations/{id}/priorities
     */
    public function store(Request $request, int $reservationId): JsonResponse
    {
        $data = $request->validate([
            'reservation_code'     => ['required', 'string'],
            'priority_criteria_id' => ['required', 'integer', 'exists:priority_criteria,id'],
        ]);

        $reservation = DormReservation::findOrFail($reservationId);

        if ($reservation->reservation_code !== $data['reservation_code']) {
            return response()->json(['message' => 'Mã hồ sơ không khớp.'], 403);
        }

        if (!in_array($reservation->status, ['submitted', 'waitlisted'], true)) {
            return response()->json(['message' => 'Không thể khai báo ưu tiên cho hồ sơ ở trạng thái này.'], 422);
        }

        $criteria = PriorityCriteria::where('id', $data['priority_criteria_id'])
            ->where('is_active', true)
            ->first();

        if (!$criteria) {
            return response()->json(['message' => 'Tiêu chí ưu tiên không hợp lệ hoặc đã bị vô hiệu hoá.'], 422);
        }

        $exists = ReservationPriority::where('dorm_reservation_id', $reservationId)
            ->where('priority_criteria_id', $criteria->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Tiêu chí này đã được khai báo rồi.'], 422);
        }

        $priority = ReservationPriority::create([
            'dorm_reservation_id'  => $reservationId,
            'priority_criteria_id' => $criteria->id,
            'status'               => 'pending',
        ]);

        return response()->json([
            'message'  => 'Đã khai báo tiêu chí ưu tiên.',
            'priority' => [
                'id'                   => $priority->id,
                'dorm_reservation_id'  => $priority->dorm_reservation_id,
                'priority_criteria_id' => $priority->priority_criteria_id,
                'status'               => $priority->status,
                'verified_by'          => $priority->verified_by,
                'verified_at'          => $priority->verified_at,
                'criteria'             => [
                    'id'   => $criteria->id,
                    'name' => $criteria->name,
                ],
                'created_at'           => $priority->created_at,
                'updated_at'           => $priority->updated_at,
            ],
        ], 201);
    }

    /**
     * Tân SV xoá khai báo ưu tiên (chỉ khi còn pending).
     * DELETE /reservation-priorities/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reservation_code' => ['required', 'string'],
        ]);

        $priority = ReservationPriority::with('dormReservation')->findOrFail($id);

        if ($priority->dormReservation->reservation_code !== $data['reservation_code']) {
            return response()->json(['message' => 'Mã hồ sơ không khớp.'], 403);
        }

        if ($priority->status !== 'pending') {
            return response()->json(['message' => 'Chỉ có thể xoá tiêu chí chưa được xác minh.'], 422);
        }

        $priority->delete();

        return response()->json(['message' => 'Đã xoá khai báo ưu tiên.']);
    }

    /**
     * Tân SV upload minh chứng cho một tiêu chí.
     * POST /reservation-priorities/{id}/evidences
     */
    public function storeEvidence(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reservation_code' => ['required', 'string'],
            'file'             => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,webp'],
        ]);

        $priority = ReservationPriority::with('dormReservation')->findOrFail($id);

        if ($priority->dormReservation->reservation_code !== $data['reservation_code']) {
            return response()->json(['message' => 'Mã hồ sơ không khớp.'], 403);
        }

        if ($priority->status !== 'pending') {
            return response()->json(['message' => 'Không thể upload minh chứng cho tiêu chí đã được xử lý.'], 422);
        }

        $file     = $request->file('file');
        $path     = $file->store('reservation-evidences', 'public');
        $fileUrl  = '/api/storage/' . $path;

        $evidence = ReservationPriorityEvidence::create([
            'reservation_priority_id' => $id,
            'file_url'                => $fileUrl,
            'original_name'           => $file->getClientOriginalName(),
            'mime_type'               => $file->getMimeType(),
            'file_size'               => $file->getSize(),
        ]);

        return response()->json(['message' => 'Đã upload minh chứng.'], 201);
    }

    /**
     * Tân SV xoá một minh chứng.
     * DELETE /reservation-priority-evidences/{id}
     */
    public function destroyEvidence(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reservation_code' => ['required', 'string'],
        ]);

        $evidence = ReservationPriorityEvidence::with('priority.dormReservation')->findOrFail($id);

        if ($evidence->priority->dormReservation->reservation_code !== $data['reservation_code']) {
            return response()->json(['message' => 'Mã hồ sơ không khớp.'], 403);
        }

        if ($evidence->priority->status !== 'pending') {
            return response()->json(['message' => 'Không thể xoá minh chứng khi tiêu chí đã được xử lý.'], 422);
        }

        // Remove stored file
        $storedPath = ltrim(str_replace('/api/storage/', '', $evidence->file_url), '/');
        Storage::delete($storedPath);

        $evidence->delete();

        return response()->json(['message' => 'Đã xoá minh chứng.']);
    }

    // =========================================================
    // Admin routes
    // =========================================================

    /**
     * Danh sách tiêu chí ưu tiên cần xác minh.
     * GET /admin/reservation-priorities
     */
    public function index(Request $request): JsonResponse
    {
        $query = ReservationPriority::with([
            'dormReservation.candidate',
            'criteria',
            'verifier',
            'evidences',
        ])->orderByRaw("FIELD(status, 'pending', 'verified', 'rejected')")
          ->orderByDesc('created_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($periodId = $request->input('registration_period_id')) {
            $query->whereHas('dormReservation', fn ($q) => $q->where('registration_period_id', $periodId));
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Admin xác minh tiêu chí.
     * PATCH /admin/reservation-priorities/{id}/verify
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $priority = ReservationPriority::with('dormReservation')->findOrFail($id);

        if ($priority->status !== 'pending') {
            return response()->json(['message' => 'Tiêu chí đã được xử lý rồi.'], 422);
        }

        $priority->update([
            'status'      => 'verified',
            'verified_by' => $request->user()?->id,
            'verified_at' => now(),
        ]);

        (new PriorityRankingService())->calculateForReservation($priority->dormReservation);

        return response()->json([
            'message'  => 'Đã xác minh tiêu chí ưu tiên.',
            'priority' => $priority->fresh(['criteria', 'evidences']),
        ]);
    }

    /**
     * Admin từ chối tiêu chí.
     * PATCH /admin/reservation-priorities/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $priority = ReservationPriority::with('dormReservation')->findOrFail($id);

        if ($priority->status !== 'pending') {
            return response()->json(['message' => 'Tiêu chí đã được xử lý rồi.'], 422);
        }

        $priority->update([
            'status'      => 'rejected',
            'verified_by' => $request->user()?->id,
            'verified_at' => now(),
        ]);

        (new PriorityRankingService())->calculateForReservation($priority->dormReservation);

        return response()->json([
            'message'  => 'Đã từ chối tiêu chí ưu tiên.',
            'priority' => $priority->fresh(['criteria', 'evidences']),
        ]);
    }
}
