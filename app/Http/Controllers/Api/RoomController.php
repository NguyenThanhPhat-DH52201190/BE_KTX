<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bed;
use App\Models\Floor;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\Room;
use App\Models\Student;
use App\Services\RoomChangeLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RoomController extends Controller
{
    public function index(): JsonResponse
    {
        $rooms = Room::query()->with(['floor', 'beds'])->orderBy('id')->get();
        $occupiedBedIds = $this->getOccupiedBedIdSet($rooms);
        $pendingMaintenanceMap = $this->getPendingRoomMaintenanceMap($rooms);

        return response()->json(
            $rooms->map(fn (Room $room) => $this->formatRoom($room, $occupiedBedIds, $pendingMaintenanceMap))->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'floor_id' => ['required', 'integer', 'exists:floors,id'],
            'room_number' => ['required', 'string', 'max:10'],
            'capacity' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string'],
            'price_per_month' => ['nullable', 'numeric'],
            'maintenance_beds' => ['nullable', 'array'],
            'maintenance_beds.*' => ['integer', 'min:1'],
        ]);

        $floor = Floor::query()->findOrFail($data['floor_id']);
        $roomNumber = trim((string) $data['room_number']);
        $this->assertUniqueRoomNumber($floor->id, $roomNumber);

        $room = DB::transaction(function () use ($floor, $data, $roomNumber) {
            $room = Room::create([
                'floor_id' => $floor->id,
                'room_number' => $roomNumber,
                'capacity' => (int) $data['capacity'],
                'price_per_month' => (float) ($data['price_per_month'] ?? 0),
                'status' => $this->normalizeRoomStatusInput($data['status'] ?? 'active'),
            ]);

            $this->syncBeds($room, (int) $data['capacity'], $data['maintenance_beds'] ?? []);

            return $room->fresh(['floor', 'beds']);
        });

        return response()->json($this->formatRoom($room, $this->getOccupiedBedIdSet(collect([$room]))), 201);
    }

    public function update(Request $request, int $roomId): JsonResponse
    {
        $data = $request->validate([
            'floor_id' => ['required', 'integer', 'exists:floors,id'],
            'room_number' => ['required', 'string', 'max:10'],
            'capacity' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string'],
            'price_per_month' => ['nullable', 'numeric'],
            'maintenance_beds' => ['nullable', 'array'],
            'maintenance_beds.*' => ['integer', 'min:1'],
        ]);

        $room = Room::query()->with(['floor', 'beds'])->findOrFail($roomId);
        $roomNumber = trim((string) $data['room_number']);
        $this->assertUniqueRoomNumber((int) $data['floor_id'], $roomNumber, $room->id);

        $occupiedCount = $this->countOccupiedBedsForRoom($room);
        if ((int) $data['capacity'] < $occupiedCount) {
            return response()->json(['message' => 'Không thể giảm sức chứa thấp hơn số giường đang có sinh viên ở.'], 422);
        }

        // syncBeds() giữ lại đúng $capacity giường có bed_number thấp nhất và xóa cứng
        // phần dư — occupied bed không nhất thiết nằm ở bed_number thấp (sinh viên chọn
        // giường tự do), nên tổng số occupied ở trên không đủ để chặn: cần kiểm tra đích
        // danh những giường sẽ bị xóa. Bed.bed_id có FK cascadeOnDelete trên occupancy nên
        // xóa nhầm giường đang ở sẽ xóa mất luôn bản ghi lưu trú của sinh viên đó.
        $bedsToRemove = $room->beds->sortBy('bed_number')->values()->slice((int) $data['capacity']);
        if ($bedsToRemove->isNotEmpty()) {
            $occupiedBedNumbers = Occupancy::occupiedBedsQuery()
                ->whereIn('bed_id', $bedsToRemove->pluck('id')->all())
                ->get()
                ->map(fn (Occupancy $occupancy) => $bedsToRemove->firstWhere('id', $occupancy->bed_id)?->bed_number)
                ->filter()
                ->sort()
                ->values();

            if ($occupiedBedNumbers->isNotEmpty()) {
                return response()->json([
                    'message' => 'Không thể giảm sức chứa: giường số ' . $occupiedBedNumbers->implode(', ') . ' sẽ bị xóa nhưng đang có sinh viên ở. Vui lòng chuyển sinh viên sang giường khác trước.',
                ], 422);
            }
        }

        $room = DB::transaction(function () use ($room, $data, $roomNumber) {
            $room->update([
                'floor_id' => (int) $data['floor_id'],
                'room_number' => $roomNumber,
                'capacity' => (int) $data['capacity'],
                'price_per_month' => (float) ($data['price_per_month'] ?? $room->price_per_month ?? 0),
                'status' => $this->normalizeRoomStatusInput($data['status'] ?? $room->status),
            ]);

            $this->syncBeds($room, (int) $data['capacity'], $data['maintenance_beds'] ?? []);

            return $room->fresh(['floor', 'beds']);
        });

        return response()->json($this->formatRoom($room, $this->getOccupiedBedIdSet(collect([$room])), $this->getPendingRoomMaintenanceMap(collect([$room]))));
    }

    public function destroy(int $roomId): JsonResponse
    {
        $room = Room::query()->with(['beds'])->findOrFail($roomId);

        if ($this->countOccupiedBedsForRoom($room) > 0) {
            return response()->json(['message' => 'Không thể xóa phòng đang có sinh viên ở.'], 422);
        }

        $room->delete();

        return response()->json(['message' => 'Đã xóa phòng.']);
    }

    /**
     * Danh sách giường của một phòng kèm thông tin sinh viên đang ở (occupancy ACTIVE).
     * GET /api/rooms/{roomId}/beds
     */
    public function beds(Request $request, int $roomId): JsonResponse
    {
        // Route dùng chung cho admin + sinh viên (chọn giường). Sinh viên không cần và
        // không nên thấy PII nhạy cảm (SĐT, email, ngày sinh, địa chỉ) của người ở phòng —
        // chỉ admin mới thấy đầy đủ.
        $isAdmin = $request->user()?->role === 'admin';

        $room = Room::query()->with(['floor', 'beds'])->findOrFail($roomId);
        $beds = $room->beds->sortBy('bed_number')->values();

        // Lấy occupancy đang hiệu lực theo từng bed_id, kèm sinh viên.
        $occupancies = Occupancy::occupiedBedsQuery()
            ->whereIn('bed_id', $beds->pluck('id')->all())
            ->with(['student', 'registration'])
            ->get()
            ->keyBy('bed_id');
        $occupancyIds = $occupancies->pluck('id')->all();
        $temporaryLogs = empty($occupancyIds)
            ? collect()
            : DB::table('room_change_log')
                ->whereIn('occupancy_id', $occupancyIds)
                ->where('change_type', 'TEMPORARY_MAINTENANCE')
                ->where('status', 'ACTIVE')
                ->orderByDesc('id')
                ->get()
                ->keyBy('occupancy_id');
        $originalRooms = Room::query()
            ->with('floor')
            ->whereIn('id', $temporaryLogs->pluck('old_room_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $originalBeds = Bed::query()
            ->whereIn('id', $temporaryLogs->pluck('old_bed_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $maintenanceLogs = DB::table('room_change_log')
            ->whereIn('old_bed_id', $beds->pluck('id')->all())
            ->where('change_type', 'TEMPORARY_MAINTENANCE')
            ->where('status', 'ACTIVE')
            ->orderByDesc('id')
            ->get()
            ->keyBy('old_bed_id');
        $maintenanceOccupancies = Occupancy::query()
            ->with(['student', 'registration'])
            ->whereIn('id', $maintenanceLogs->pluck('occupancy_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $temporaryBeds = Bed::query()
            ->whereIn('id', $maintenanceLogs->pluck('new_bed_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $historyLogs = DB::table('room_change_log')
            ->whereIn('change_type', ['PERMANENT', 'TEMPORARY_MAINTENANCE'])
            ->where(function ($query) use ($beds) {
                $bedIds = $beds->pluck('id')->all();
                $query->whereIn('old_bed_id', $bedIds)
                    ->orWhereIn('new_bed_id', $bedIds);
            })
            ->orderByDesc('id')
            ->get();
        $historyOccupancies = Occupancy::query()
            ->with(['student'])
            ->whereIn('id', $historyLogs->pluck('occupancy_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $historyBedIds = $historyLogs
            ->flatMap(fn ($log) => [$log->old_bed_id, $log->new_bed_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $historyBeds = Bed::query()
            ->whereIn('id', $historyBedIds)
            ->get()
            ->keyBy('id');
        $historyRoomIds = $historyLogs
            ->flatMap(fn ($log) => [$log->old_room_id, $log->new_room_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $historyRooms = Room::query()
            ->with('floor')
            ->whereIn('id', $historyRoomIds)
            ->get()
            ->keyBy('id');

        // Lý do bảo trì admin tự nhập (MaintenanceRequest::reason) — dùng để hiện đúng lý do
        // thật trong lịch sử thay vì chỉ 1 câu mẫu chung chung (xem RoomChangeLogService::
        // buildLabel(), báo cáo 28/07).
        $maintenanceRequestReasons = MaintenanceRequest::query()
            ->whereIn('id', $historyLogs->pluck('maintenance_request_id')->filter()->unique()->all())
            ->pluck('reason', 'id');

        $roomInMaintenance = strtoupper((string) $room->status) === 'MAINTENANCE';

        $payload = $beds->map(function (Bed $bed) use (
            $occupancies,
            $temporaryLogs,
            $originalRooms,
            $originalBeds,
            $maintenanceLogs,
            $maintenanceOccupancies,
            $temporaryBeds,
            $historyLogs,
            $historyOccupancies,
            $historyBeds,
            $historyRooms,
            $maintenanceRequestReasons,
            $roomInMaintenance,
            $isAdmin
        ) {
            $occupancy = $occupancies->get($bed->id);
            $student = $occupancy?->student;
            $temporaryLog = $occupancy ? $temporaryLogs->get($occupancy->id) : null;
            $originalRoom = $temporaryLog ? $originalRooms->get($temporaryLog->old_room_id) : null;
            $originalBed = $temporaryLog ? $originalBeds->get($temporaryLog->old_bed_id) : null;
            $maintenanceLog = $maintenanceLogs->get($bed->id);
            $maintenanceOccupancy = $maintenanceLog ? $maintenanceOccupancies->get($maintenanceLog->occupancy_id) : null;
            $temporaryBed = $maintenanceLog ? $temporaryBeds->get($maintenanceLog->new_bed_id) : null;

            $displayStatus = 'empty';
            if ($roomInMaintenance || strtoupper((string) $bed->status) === 'MAINTENANCE') {
                $displayStatus = 'maintenance';
            } elseif ($occupancy?->status === 'PENDING_PAYMENT') {
                $displayStatus = 'reserved';
            } elseif ($student) {
                $displayStatus = 'occupied';
            }
            // Bed-level: all TEMPORARY_MAINTENANCE events involving this physical bed (for maintenance_history)
            $bedHistoryLogs = $historyLogs->filter(
                fn ($log) => (int) $log->old_bed_id === (int) $bed->id || (int) $log->new_bed_id === (int) $bed->id,
            )->values();
            // Student-level: only the current occupant's own moves (for transfer_history) —
            // nếu giường đang trống do bảo trì (chưa ai ở, occupancy hiện tại null), lấy tạm
            // occupancy của người VỪA rời đi (qua $maintenanceLog) để vẫn hiện được lịch sử
            // thay vì trống trơn gây hiểu lầm mất dữ liệu (báo cáo 28/07).
            $currentOccupancyId = $occupancy?->id ?? $maintenanceLog?->occupancy_id;
            $studentHistoryLogs = $currentOccupancyId
                ? $historyLogs->filter(fn ($log) => (int) $log->occupancy_id === $currentOccupancyId)->values()
                : collect();
            $roomCodeFor = function ($roomId) use ($historyRooms) {
                $room = $historyRooms->get($roomId);
                return $room ? (($room->floor?->building_code ?? '') . $room->room_number) : null;
            };

            $formatHistory = function ($log) use ($historyOccupancies, $historyBeds, $historyRooms, $maintenanceRequestReasons) {
                $historyOccupancy = $historyOccupancies->get($log->occupancy_id);
                $oldRoom = $historyRooms->get($log->old_room_id);
                $newRoom = $historyRooms->get($log->new_room_id);
                $oldBed = $historyBeds->get($log->old_bed_id);
                $newBed = $historyBeds->get($log->new_bed_id);
                // resolveHistoryReason()/buildLabel() cần old_room_code cho câu "Chuyển tạm do bảo trì phòng X".
                $log->old_room_code = $oldRoom ? (($oldRoom->floor?->building_code ?? '') . $oldRoom->room_number) : null;
                $customReason = $log->maintenance_request_id ? $maintenanceRequestReasons->get($log->maintenance_request_id) : null;
                $reason = $this->resolveHistoryReason($log, $customReason);

                return [
                    'id' => $log->id,
                    'student_name' => $historyOccupancy?->student?->full_name,
                    'student_code' => $historyOccupancy?->student?->student_code,
                    'old_room_code' => $log->old_room_code,
                    'old_bed_number' => $oldBed?->bed_number ? (string) $oldBed->bed_number : null,
                    'new_room_code' => $newRoom ? (($newRoom->floor?->building_code ?? '') . $newRoom->room_number) : null,
                    'new_bed_number' => $newBed?->bed_number ? (string) $newBed->bed_number : null,
                    'reason' => $reason,
                    'change_type' => $log->change_type,
                    'status' => $log->status,
                    'is_temporary' => (bool) $log->is_temporary,
                    'is_initial_assignment' => false,
                    'transferred_at' => $log->transferred_at,
                    'completed_at' => $log->completed_at,
                    'expected_return_date' => $log->expected_return_date,
                    'maintenance_request_id' => $log->maintenance_request_id,
                ];
            };

            // Tách các dòng "xếp chỗ ban đầu" (assign_room/select_bed, không phải chuyển thật
            // sự — không có phòng/giường cũ) ra khỏi lịch sử chuyển thật, gộp thành 1 mốc duy
            // nhất (giống StudentRoomChangeHistoryController) thay vì hiện như 2-3 lần "chuyển".
            $initialAssignmentLogs = $studentHistoryLogs
                ->filter(fn ($log) => RoomChangeLogService::isInitialAssignment($log))
                ->sortBy('transferred_at')
                ->values();
            $realTransferLogs = $studentHistoryLogs
                ->reject(fn ($log) => RoomChangeLogService::isInitialAssignment($log))
                ->values();

            $transferHistory = $realTransferLogs->map($formatHistory)->all();

            if ($initialAssignmentLogs->isNotEmpty()) {
                $firstInitial = $initialAssignmentLogs->first();
                $lastInitial = $initialAssignmentLogs->last();
                $initialOccupancy = $historyOccupancies->get($firstInitial->occupancy_id);
                $lastNewBed = $historyBeds->get($lastInitial->new_bed_id);

                $transferHistory[] = [
                    'id' => $firstInitial->id,
                    'student_name' => $initialOccupancy?->student?->full_name,
                    'student_code' => $initialOccupancy?->student?->student_code,
                    'old_room_code' => null,
                    'old_bed_number' => null,
                    'new_room_code' => $roomCodeFor($lastInitial->new_room_id),
                    'new_bed_number' => $lastNewBed?->bed_number ? (string) $lastNewBed->bed_number : null,
                    'reason' => RoomChangeLogService::INITIAL_ASSIGNMENT_LABEL,
                    'change_type' => $lastInitial->change_type,
                    'status' => null,
                    'is_temporary' => false,
                    'is_initial_assignment' => true,
                    'transferred_at' => $firstInitial->transferred_at,
                    'completed_at' => null,
                    'expected_return_date' => null,
                    'maintenance_request_id' => null,
                ];
            }

            return [
                'id' => $bed->id,
                'bed_number' => (string) $bed->bed_number,
                'position' => strtolower((string) $bed->position ?: 'upper') === 'lower' ? 'bottom' : 'top',
                'status' => strtoupper((string) $bed->status) === 'MAINTENANCE' ? 'maintenance' : 'active',
                'display_status' => $displayStatus,
                'occupied' => (bool) $student,
                'transfer_history' => $transferHistory,
                'maintenance_history' => $bedHistoryLogs
                    ->filter(fn ($log) => $log->maintenance_request_id || in_array($log->transfer_reason, ['BED_MAINTENANCE', 'ROOM_MAINTENANCE'], true))
                    ->map($formatHistory)
                    ->all(),
                'maintenance_assignment' => $maintenanceLog && $maintenanceOccupancy?->student ? [
                    'occupancy_id' => $maintenanceOccupancy->id,
                    'student_name' => $maintenanceOccupancy->student->full_name,
                    'student_code' => $maintenanceOccupancy->student->student_code,
                    'student_avatar' => $this->getImageUrl($maintenanceOccupancy->student->avatar ?? $maintenanceOccupancy->registration?->avatar_url),
                    'temporary_bed_id' => $maintenanceLog->new_bed_id,
                    'temporary_bed_number' => $temporaryBed?->bed_number ? (string) $temporaryBed->bed_number : null,
                    'transferred_at' => $maintenanceLog->transferred_at,
                    'expected_return_date' => $maintenanceLog->expected_return_date,
                    'reason' => $maintenanceLog->transfer_reason,
                    'maintenance_request_id' => $maintenanceLog->maintenance_request_id,
                    'can_return' => true,
                ] : null,
                'student' => $student ? array_merge([
                    'id' => $student->id,
                    'student_code' => $student->student_code,
                    'full_name' => $student->full_name,
                    // Sinh viên nguồn giữ chỗ tân sinh viên chưa chắc có Student.avatar (ảnh chỉ
                    // lưu ở DormReservation/Registration.avatar_url lúc nộp hồ sơ) — fallback
                    // sang avatar_url của Registration gắn với occupancy này.
                    'avatar' => $this->getImageUrl($student->avatar ?? $occupancy?->registration?->avatar_url),
                    'class_name' => $student->class_name,
                    'faculty' => $student->faculty,
                    'academic_status' => $student->academic_status,
                    'gender' => $student->gender,
                    'course_year' => $student->course_year,
                ], $isAdmin ? [
                    'phone' => $student->phone,
                    'email' => $student->email,
                    'date_of_birth' => $student->date_of_birth,
                    'permanent_address' => $student->permanent_address,
                ] : [], [
                    'check_in_date' => $occupancy?->check_in_date,
                    'check_out_date' => $occupancy?->check_out_date,
                    'occupancy' => $occupancy ? [
                        'id' => $occupancy->id,
                        'bed_id' => $occupancy->bed_id,
                        'room_id' => $occupancy->room_id,
                        'check_in_date' => $occupancy->check_in_date,
                        'check_out_date' => $occupancy->check_out_date,
                        'status' => $occupancy->status,
                        'checkout_request' => $occupancy->pendingCheckoutRequest ? [
                            'expected_leave_date' => $occupancy->pendingCheckoutRequest->expected_leave_date?->toDateString(),
                            'reason' => $occupancy->pendingCheckoutRequest->reason,
                        ] : null,
                    ] : null,
                    'temporary_assignment' => $temporaryLog ? [
                        'is_temporary' => true,
                        'original_room_id' => $temporaryLog->old_room_id,
                        'original_room_code' => $originalRoom ? (($originalRoom->floor?->building_code ?? '') . $originalRoom->room_number) : null,
                        'original_bed_id' => $temporaryLog->old_bed_id,
                        'original_bed_number' => $originalBed?->bed_number ? (string) $originalBed->bed_number : null,
                        'reason' => $temporaryLog->transfer_reason,
                        'transferred_at' => $temporaryLog->transferred_at,
                        'expected_return_date' => $temporaryLog->expected_return_date,
                        'change_type' => $temporaryLog->change_type,
                        'maintenance_request_id' => $temporaryLog->maintenance_request_id,
                    ] : null,
                ]) : null,
            ];
        });

        return response()->json([
            'room_id' => $room->id,
            'room_status' => strtoupper((string) $room->status ?: 'ACTIVE'),
            'capacity' => (int) $room->capacity,
            'beds' => $payload->all(),
        ]);
    }

    /**
     * Dựng URL ảnh đầy đủ theo môi trường (giống RegistrationController).
     */
    private function getImageUrl($path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // Một số bản ghi (vd. Registration convert từ hồ sơ giữ chỗ tân sinh viên) đã có sẵn
        // prefix storage trong path lưu — phải strip trước, tránh double-prefix khiến ảnh 404.
        $cleanPath = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));
        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';

        return $isProduction
            ? url('/api/storage/' . $cleanPath)
            : url('/storage/' . $cleanPath);
    }

    private function resolveHistoryReason(object $log, ?string $customReason = null): ?string
    {
        $reason = $log->transfer_reason;
        $studentRequestReasons = [
            'student_room_change_request' => 'Đổi phòng theo yêu cầu sinh viên',
            'student_bed_change_request' => 'Đổi giường theo yêu cầu sinh viên',
            'student_roommate_request' => 'Ở cùng bạn theo yêu cầu sinh viên',
        ];

        if (! array_key_exists((string) $reason, $studentRequestReasons)) {
            return RoomChangeLogService::buildLabel($log, $customReason);
        }

        return $studentRequestReasons[(string) $reason];
    }

    public function updateBed(Request $request, int $roomId, int $bedId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,maintenance'],
        ]);

        $room = Room::query()->with(['floor', 'beds'])->findOrFail($roomId);
        $bed = $room->beds->firstWhere('id', $bedId);

        if (!$bed) {
            return response()->json(['message' => 'Không tìm thấy giường.'], 404);
        }

        $bed->update(['status' => strtolower($data['status']) === 'maintenance' ? 'maintenance' : 'active']);

        $room = $room->fresh(['floor', 'beds']);

        return response()->json($this->formatRoom($room, $this->getOccupiedBedIdSet(collect([$room])), $this->getPendingRoomMaintenanceMap(collect([$room]))));
    }

    public function transferBedOccupancy(Request $request, int $roomId, int $bedId): JsonResponse
    {
        $data = $request->validate([
            'target_bed_id' => ['required', 'integer', 'exists:beds,id'],
            'source_status' => ['nullable', 'string', 'in:active,maintenance'],
            'reason' => ['nullable', 'string', 'max:255'],
            'change_type' => ['nullable', 'string', 'in:PERMANENT,TEMPORARY_MAINTENANCE'],
            'transfer_type' => ['nullable', 'string', 'in:PERMANENT,TEMP_MAINTENANCE'],
            'expected_return_date' => ['nullable', 'date'],
            'maintenance_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (($data['transfer_type'] ?? null) === 'TEMP_MAINTENANCE') {
            if (empty($data['expected_return_date'])) {
                return response()->json(['message' => 'Vui lòng chọn ngày dự kiến hoàn tất bảo trì.'], 422);
            }

            if (trim((string) ($data['maintenance_reason'] ?? '')) === '') {
                return response()->json(['message' => 'Vui lòng nhập lý do bảo trì.'], 422);
            }
        }

        $room = Room::query()->with(['floor', 'beds'])->findOrFail($roomId);
        $sourceBed = $room->beds->firstWhere('id', $bedId);

        if (!$sourceBed) {
            return response()->json(['message' => 'Không tìm thấy giường nguồn.'], 404);
        }

        if ((int) $data['target_bed_id'] === (int) $sourceBed->id) {
            return response()->json(['message' => 'Giường chuyển đến phải khác giường hiện tại.'], 422);
        }

        $targetBed = Bed::query()->findOrFail((int) $data['target_bed_id']);

        if ((int) $targetBed->room_id !== (int) $room->id) {
            return response()->json(['message' => 'Hiện chỉ hỗ trợ chuyển giường trong cùng phòng.'], 422);
        }

        if (strtolower((string) $targetBed->status) === 'maintenance') {
            return response()->json(['message' => 'Giường chuyển đến đang bảo trì.'], 422);
        }

        $notifData = null;

        $updatedRoom = DB::transaction(function () use ($room, $sourceBed, $targetBed, $data, &$notifData) {
            $occupancy = Occupancy::occupiedBedsQuery()
                ->where('bed_id', $sourceBed->id)
                ->lockForUpdate()
                ->first();

            if (!$occupancy) {
                abort(response()->json(['message' => 'Không tìm thấy sinh viên đang ở giường này.'], 422));
            }

            $targetOccupied = Occupancy::occupiedBedsQuery()
                ->where('bed_id', $targetBed->id)
                ->lockForUpdate()
                ->exists();

            if ($targetOccupied) {
                abort(response()->json(['message' => 'Giường chuyển đến đã có sinh viên ở.'], 422));
            }

            $oldRoomId = (int) $occupancy->room_id;
            $oldBedId = (int) $occupancy->bed_id;
            $changeType = ($data['transfer_type'] ?? null) === 'TEMP_MAINTENANCE'
                ? 'TEMPORARY_MAINTENANCE'
                : ($data['change_type'] ?? 'PERMANENT');
            $maintenanceReason = trim((string) ($data['maintenance_reason'] ?? $data['reason'] ?? ''));
            $maintenanceRequest = null;

            if ($changeType === 'TEMPORARY_MAINTENANCE') {
                $maintenanceRequest = MaintenanceRequest::create([
                    'type' => 'BED',
                    'room_id' => $room->id,
                    'bed_id' => $sourceBed->id,
                    'reason' => $maintenanceReason !== '' ? $maintenanceReason : 'BED_MAINTENANCE',
                    'status' => 'IN_PROGRESS',
                    'started_at' => now(),
                ]);
            }

            $occupancy->room_id = $targetBed->room_id;
            $occupancy->bed_id = $targetBed->id;
            $occupancy->save();

            $sourceBed->status = $changeType === 'TEMPORARY_MAINTENANCE' ? 'maintenance' : ($data['source_status'] ?? 'active');
            $sourceBed->save();

            $targetBed->status = 'active';
            $targetBed->save();

            DB::table('room_change_log')->insert([
                'occupancy_id' => $occupancy->id,
                'old_room_id' => $oldRoomId,
                'old_bed_id' => $oldBedId,
                'new_room_id' => (int) $occupancy->room_id,
                'new_bed_id' => (int) $occupancy->bed_id,
                'transfer_reason' => $changeType === 'TEMPORARY_MAINTENANCE'
                    ? ($maintenanceReason !== '' ? $maintenanceReason : 'BED_MAINTENANCE')
                    : ($data['reason'] ?? 'admin_transfer_bed'),
                'change_type' => $changeType,
                'maintenance_request_id' => $maintenanceRequest?->id,
                'status' => $changeType === 'TEMPORARY_MAINTENANCE' ? 'ACTIVE' : null,
                'change_source' => 'admin',
                'is_temporary' => $changeType === 'TEMPORARY_MAINTENANCE',
                'expected_return_date' => $changeType === 'TEMPORARY_MAINTENANCE' ? ($data['expected_return_date'] ?? null) : null,
                'transferred_at' => now(),
            ]);

            // Prepare notification inside transaction so it rolls back on failure
            $notifData = $this->createBedTransferNotification(
                $occupancy->student_id,
                $oldRoomId,
                $oldBedId,
                (int) $targetBed->id,
                $changeType,
                $maintenanceReason,
                $changeType === 'TEMPORARY_MAINTENANCE' ? ($data['expected_return_date'] ?? null) : null,
            );

            return $room->fresh(['floor', 'beds']);
        });

        // Send email outside the transaction
        if ($notifData) {
            $this->sendBedTransferEmail($notifData);
        }

        return response()->json($this->formatRoom($updatedRoom, $this->getOccupiedBedIdSet(collect([$updatedRoom])), $this->getPendingRoomMaintenanceMap(collect([$updatedRoom]))));
    }

    private function createBedTransferNotification(
        int $studentId,
        int $oldRoomId,
        int $oldBedId,
        int $newBedId,
        string $changeType,
        string $reason,
        ?string $expectedReturnDate,
    ): ?array {
        $student = Student::find($studentId);
        if (! $student) {
            return null;
        }

        $oldRoom = Room::with('floor')->find($oldRoomId);
        $oldBed  = Bed::find($oldBedId);
        $newBed  = Bed::with('room.floor')->find($newBedId);

        $oldCode  = ($oldRoom?->floor?->building_code ?? '') . ($oldRoom?->room_number ?? '');
        $newCode  = ($newBed?->room?->floor?->building_code ?? '') . ($newBed?->room?->room_number ?? '');
        $oldLabel = "Phòng {$oldCode}, Giường " . ($oldBed?->bed_number ?? '?');
        $newLabel = "Phòng {$newCode}, Giường " . ($newBed?->bed_number ?? '?');

        if ($changeType === 'PERMANENT') {
            $title   = 'Thay đổi giường lưu trú';
            $content = "Bạn đã được chuyển vĩnh viễn từ {$oldLabel} sang {$newLabel}.";
            if ($reason !== '') {
                $content .= " Lý do: {$reason}.";
            }
            $type = 'bed_transferred_permanent';
        } else {
            $title   = 'Chuyển giường tạm thời — Bảo trì';
            $content = "{$oldLabel} đang được bảo trì. Bạn được chuyển tạm sang {$newLabel}.";
            if ($expectedReturnDate) {
                $dt       = \Carbon\Carbon::parse($expectedReturnDate)->format('d/m/Y');
                $content .= " Dự kiến hoàn tất: {$dt}.";
            }
            $type = 'bed_transferred_temporary';
        }

        try {
            $notification = Notification::create([
                'student_id'  => $student->id,
                'title'       => $title,
                'content'     => $content,
                'type'        => $type,
                'target_type' => 'individual',
                'send_email'  => true,
            ]);
            DB::table('notification_recipient')->insert([
                'notification_id' => $notification->id,
                'student_id'      => $student->id,
                'is_read'         => false,
                'read_at'         => null,
            ]);
        } catch (\Exception) {}

        return [
            'email'      => $student->email,
            'name'       => $student->full_name ?? '',
            'subject'    => 'KTX — ' . $title,
            'title'      => $title,
            'content'    => $content,
            'type'       => $type,
            'student_id' => $student->id,
        ];
    }

    private function sendBedTransferEmail(array $data): void
    {
        if (empty($data['email'])) {
            return;
        }
        try {
            $name    = htmlspecialchars($data['name']);
            $title   = htmlspecialchars($data['title']);
            $content = nl2br(htmlspecialchars($data['content']));
            $body    = "<div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152'>
                <h2 style='color:#244cb8;margin-top:0'>{$title}</h2>
                <p>Xin chào <strong>{$name}</strong>,</p>
                <p>{$content}</p>
                <p style='color:#6b7280;font-size:12px;margin-top:32px'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
            </div>";
            Mail::send([], [], function ($message) use ($data, $body) {
                $message->to($data['email'], $data['name'])
                    ->subject($data['subject'])
                    ->html($body);
            });
        } catch (\Exception $e) {
            Log::error('Gửi email thông báo thất bại', [
                'type'       => $data['type'] ?? null,
                'student_id' => $data['student_id'] ?? null,
                'email'      => $data['email'],
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function assertUniqueRoomNumber(int $floorId, string $roomNumber, ?int $ignoreRoomId = null): void
    {
        $query = Room::query()
            ->where('floor_id', $floorId)
            ->where('room_number', $roomNumber);

        if ($ignoreRoomId !== null) {
            $query->where('id', '!=', $ignoreRoomId);
        }

        if ($query->exists()) {
            abort(response()->json(['message' => 'Phòng đã tồn tại.'], 422));
        }
    }

    private function syncBeds(Room $room, int $capacity, array $maintenanceBeds = []): void
    {
        $beds = $room->beds()->orderBy('bed_number')->get();

        foreach ($beds->take($capacity) as $index => $bed) {
            $bed->update([
                'bed_number' => $index + 1,
                'position' => ($index + 1) % 2 === 1 ? 'upper' : 'lower',
                'status' => in_array($index + 1, $maintenanceBeds, true) ? 'maintenance' : 'active',
            ]);
        }

        if ($beds->count() > $capacity) {
            $beds->slice($capacity)->each->delete();
        }

        for ($bedNumber = $beds->count() + 1; $bedNumber <= $capacity; $bedNumber++) {
            Bed::create([
                'room_id' => $room->id,
                'bed_number' => $bedNumber,
                'position' => $bedNumber % 2 === 1 ? 'upper' : 'lower',
                'status' => in_array($bedNumber, $maintenanceBeds, true) ? 'maintenance' : 'active',
            ]);
        }
    }

    private function countOccupiedBedsForRoom(Room $room): int
    {
        return Occupancy::occupiedBedsQuery()
            ->where('room_id', $room->id)
            ->count();
    }

    private function isBedOccupied(int $bedId): bool
    {
        return Occupancy::occupiedBedsQuery()
            ->where('bed_id', $bedId)
            ->exists();
    }

    /**
     * Map room_id => thông tin yêu cầu bảo trì cả phòng đang PENDING (đã lên lịch cho
     * tương lai, chưa tới ngày bắt đầu di dời) — dùng để FE hiện badge riêng cho phòng
     * đã có kế hoạch bảo trì chờ sẵn, tránh trường hợp phòng hiện "Còn trống" bình
     * thường dù đã có lịch, khiến admin vô tình lên lịch/bắt đầu chồng lần nữa.
     */
    private function getPendingRoomMaintenanceMap($rooms): array
    {
        $roomIds = $rooms->pluck('id')->unique()->values();

        if ($roomIds->isEmpty()) {
            return [];
        }

        return MaintenanceRequest::query()
            ->where('type', 'ROOM')
            ->where('status', 'PENDING')
            ->whereIn('room_id', $roomIds->all())
            ->orderByDesc('id')
            ->get()
            ->keyBy('room_id')
            ->map(fn (MaintenanceRequest $mr) => [
                'id' => $mr->id,
                'reason' => $mr->reason,
                'started_at' => optional($mr->started_at)->toDateString(),
                'expected_end_at' => optional($mr->expected_end_at)->toDateString(),
            ])
            ->all();
    }

    private function getOccupiedBedIdSet($rooms): array
    {
        $bedIds = $rooms->flatMap(fn (Room $room) => $room->beds->pluck('id'))->unique()->values();

        if ($bedIds->isEmpty()) {
            return [];
        }

        return Occupancy::occupiedBedsQuery()
            ->whereIn('bed_id', $bedIds->all())
            ->pluck('bed_id')
            ->mapWithKeys(fn ($bedId) => [(int) $bedId => true])
            ->all();
    }

    private function normalizeRoomStatusInput(string $status): string
    {
        $value = strtolower(trim($status));

        return $value === 'maintenance' ? 'maintenance' : 'active';
    }

    private function formatRoom(Room $room, array $occupiedBedIds, array $pendingMaintenanceMap = []): array
    {
        $beds = $room->beds->sortBy('bed_number')->values();
        $roomInMaintenance = strtoupper((string) $room->status) === 'MAINTENANCE';
        $bedPayload = $beds->map(function (Bed $bed) use ($occupiedBedIds) {
            $occupied = isset($occupiedBedIds[(int) $bed->id]);

            return [
                'id' => $bed->id,
                'room_id' => $bed->room_id,
                'bed_number' => (string) $bed->bed_number,
                'position' => strtoupper((string) $bed->position ?: 'UPPER'),
                'status' => strtoupper((string) $bed->status) === 'MAINTENANCE' ? 'MAINTENANCE' : 'ACTIVE',
                'occupied' => $occupied,
            ];
        });

        if ($roomInMaintenance) {
            $occupiedBeds = 0;
            $availableBeds = 0;
            $maintenanceBeds = (int) $room->capacity;
            $activeBeds = 0;
        } else {
            $occupiedBeds = $bedPayload->filter(fn (array $bed) => $bed['occupied'])->count();
            $maintenanceBeds = $bedPayload->filter(fn (array $bed) => $bed['status'] === 'MAINTENANCE')->count();
            $activeBeds = max($room->capacity - $maintenanceBeds, 0);
            $availableBeds = max($activeBeds - $occupiedBeds, 0);
        }

        $displayStatus = $roomInMaintenance
            ? 'MAINTENANCE'
            : ($availableBeds === 0 && $activeBeds > 0 ? 'FULL' : 'AVAILABLE');

        return [
            'id' => $room->id,
            'floor_id' => $room->floor_id,
            'building_code' => $room->floor?->building_code ?? '',
            'room_number' => (string) $room->room_number,
            'floor_number' => (int) ($room->floor?->floor_number ?? 0),
            'capacity' => (int) $room->capacity,
            'price_per_month' => (float) $room->price_per_month,
            'status' => $displayStatus,
            'floor' => $room->floor ? [
                'id' => $room->floor->id,
                'building_code' => $room->floor->building_code,
                'floor_number' => $room->floor->floor_number,
                'gender' => strtoupper((string) $room->floor->gender ?: 'MALE'),
                'status' => strtoupper((string) $room->floor->status ?: 'ACTIVE'),
            ] : null,
            'beds' => $bedPayload->all(),
            'occupied_beds' => $occupiedBeds,
            'available_beds' => $availableBeds,
            'maintenance_beds' => $maintenanceBeds,
            'scheduled_maintenance' => $pendingMaintenanceMap[$room->id] ?? null,
        ];
    }
}
