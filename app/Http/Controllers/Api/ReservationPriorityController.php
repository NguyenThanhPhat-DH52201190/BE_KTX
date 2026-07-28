<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\DormReservation;
use App\Models\PriorityCriteria;
use App\Models\ReservationPriority;
use App\Models\ReservationPriorityEvidence;
use App\Services\PriorityRankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReservationPriorityController extends Controller
{
    /**
     * Đường dẫn minh chứng ưu tiên lưu trong DB là đường dẫn TƯƠNG ĐỐI — dùng thẳng làm
     * <img src> ở FE sẽ bị trình duyệt tự ghép với domain của chính FE (Vercel), không phải
     * domain BE (Railway), vỡ ảnh khi FE/BE khác domain nhau (báo cáo 28/07). Copy cùng logic
     * với RegistrationController::getImageUrl().
     */
    private function getImageUrl($path)
    {
        if (empty($path)) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $cleanPath = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));

        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';

        if ($isProduction) {
            return url('/api/storage/' . $cleanPath);
        }

        return url('/storage/' . $cleanPath);
    }

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

        $file = $request->file('file');
        $dir  = 'reservation-evidences';

        // Lấy metadata TRƯỚC khi move() — move() thật sự DI CHUYỂN file khỏi thư mục tạm
        // /tmp/..., nên gọi getMimeType()/getSize() SAU move() sẽ cố đọc lại file đã không
        // còn tồn tại ở đường dẫn tạm nữa, ném lỗi "file does not exist" (Symfony
        // FileinfoMimeTypeGuesser) → 500 dù file ĐÃ lưu thành công vào volume rồi (báo cáo
        // 28/07: ảnh minh chứng lưu được nhưng response vẫn 500).
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();

        if (StorageHelper::isRailwayWithVolume()) {
            $filename = 'evidence_' . $id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $volumePath = env('RAILWAY_VOLUME_PATH', '/data/storage');
            $fullDir = $volumePath . '/' . $dir;

            if (!file_exists($fullDir)) {
                mkdir($fullDir, 0755, true);
            }

            $file->move($fullDir, $filename);
            $path = $dir . '/' . $filename;
        } else {
            $path = $file->store($dir, 'public');
        }

        $fileUrl = '/api/storage/' . $path;

        $evidence = ReservationPriorityEvidence::create([
            'reservation_priority_id' => $id,
            'file_url'                => $fileUrl,
            'original_name'           => $originalName,
            'mime_type'               => $mimeType,
            'file_size'               => $fileSize,
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

        $paginated = $query->paginate(20);
        foreach ($paginated as $priority) {
            foreach ($priority->evidences as $ev) {
                $ev->file_url = $this->getImageUrl($ev->file_url);
            }
        }

        return response()->json($paginated);
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

        // Minh chứng ưu tiên không hợp lệ — hồ sơ không còn đủ điều kiện tham gia ranking/
        // duyệt/promotion/convert. Chuyển thẳng sang rejected trừ khi đã ở trạng thái cuối
        // (converted/expired), trường hợp đó KHÔNG tự động sửa, chỉ log cảnh báo
        // để admin xử lý riêng (không tự đổi hồ sơ đã converted/ACTIVE).
        $reservation = $priority->dormReservation;
        if ($reservation && !in_array($reservation->status, ['converted', 'expired'], true)) {
            $reservation->update([
                'status'            => 'rejected',
                'rejection_reason'  => 'Minh chứng ưu tiên không hợp lệ.',
            ]);
        } elseif ($reservation) {
            Log::warning('[ReservationPriorityController::reject] Minh chứng ưu tiên bị từ chối nhưng reservation đã ở trạng thái cuối (converted/expired) — không tự động chuyển, cần admin xử lý riêng.', [
                'reservation_id' => $reservation->id,
                'priority_id'    => $priority->id,
                'status'         => $reservation->status,
            ]);
        }

        return response()->json([
            'message'     => 'Đã từ chối tiêu chí ưu tiên.',
            'priority'    => $priority->fresh(['criteria', 'evidences']),
            'reservation' => $reservation?->fresh(),
        ]);
    }
}
