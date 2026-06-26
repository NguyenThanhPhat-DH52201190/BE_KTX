<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessStudentSupportRequest;
use App\Http\Requests\StoreStudentSupportRequest;
use App\Http\Resources\StudentSupportRequestResource;
use App\Models\Account;
use App\Models\AdminNotification;
use App\Models\Bed;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\Room;
use App\Models\Student;
use App\Models\StudentSupportRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class StudentSupportRequestController extends Controller
{
    private const WITH_ALL = ['student', 'targetRoom.floor', 'targetBed', 'targetStudent'];

    public function studentIndex(Request $request): JsonResponse
    {
        $student = $this->resolveStudent($request);

        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $items = StudentSupportRequest::query()
            ->with(self::WITH_ALL)
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(StudentSupportRequestResource::collection($items)->resolve());
    }

    public function studentShow(Request $request, int $id): JsonResponse
    {
        $student = $this->resolveStudent($request);

        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $supportRequest = StudentSupportRequest::query()
            ->with(self::WITH_ALL)
            ->where('student_id', $student->id)
            ->findOrFail($id);

        return response()->json((new StudentSupportRequestResource($supportRequest))->resolve());
    }

    public function roommateTarget(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_code' => ['required', 'string', 'exists:students,student_code'],
        ]);

        $student = Student::query()
            ->where('student_code', $validated['student_code'])
            ->firstOrFail();

        $occupancy = Occupancy::occupiedBedsQuery()
            ->with(['room.floor', 'bed'])
            ->where('student_id', $student->id)
            ->first();

        if (! $occupancy || ! $occupancy->room_id) {
            return response()->json([
                'message' => 'Sinh viên này hiện không có phòng lưu trú đang hoạt động.',
            ], 422);
        }

        if (! $occupancy->room || strtolower((string) $occupancy->room->status) === 'maintenance') {
            return response()->json([
                'message' => 'Phòng của sinh viên này hiện đang bảo trì, chưa thể gửi yêu cầu ở cùng.',
            ], 422);
        }

        $occupiedBedIds = Occupancy::occupiedBedsQuery()
            ->whereNotNull('bed_id')
            ->pluck('bed_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $availableBeds = Bed::query()
            ->where('room_id', $occupancy->room_id)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->whereNotIn('id', $occupiedBedIds)
            ->orderBy('bed_number')
            ->get(['id', 'bed_number']);

        return response()->json([
            'student' => [
                'id'           => (int) $student->id,
                'student_code' => $student->student_code ?? '',
                'full_name'    => $student->full_name ?? '',
                'email'        => $student->email ?? '',
            ],
            'room' => [
                'id'            => (int) $occupancy->room_id,
                'room_number'   => (string) ($occupancy->room?->room_number ?? ''),
                'building_code' => $occupancy->room?->floor?->building_code ?? '',
                'floor_number'  => (int) ($occupancy->room?->floor?->floor_number ?? 0),
            ],
            'current_bed' => $occupancy->bed ? [
                'id'         => (int) $occupancy->bed->id,
                'bed_number' => (string) $occupancy->bed->bed_number,
            ] : null,
            'available_beds' => $availableBeds->map(fn (Bed $bed) => [
                'id'         => (int) $bed->id,
                'bed_number' => (string) $bed->bed_number,
            ])->values(),
        ]);
    }

    public function store(StoreStudentSupportRequest $request): JsonResponse
    {
        $student = $this->resolveStudent($request);

        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        $requestType = $request->string('request_type')->toString();

        $existingPending = StudentSupportRequest::query()
            ->where('student_id', $student->id)
            ->where('request_type', $requestType)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($existingPending) {
            $typeLabel = $this->getTypeLabel($requestType);
            return response()->json([
                'message' => "Bạn đã có yêu cầu \"{$typeLabel}\" đang chờ xử lý. Vui lòng chờ admin phản hồi trước khi gửi yêu cầu mới.",
            ], 422);
        }

        if (in_array($requestType, ['room_change', 'bed_change', 'roommate_request'], true)) {
            $invalidTargetResponse = $this->validateTransferRequestTarget($request, $student);
            if ($invalidTargetResponse) {
                return $invalidTargetResponse;
            }
        }

        $supportRequest = StudentSupportRequest::query()->create([
            'student_id'        => $student->id,
            'request_type'      => $requestType,
            'title'             => trim($request->string('title')->toString()),
            'content'           => trim($request->string('content')->toString()),
            'attachment_url'    => $request->filled('attachment_url')
                ? trim($request->string('attachment_url')->toString())
                : null,
            'status'            => 'pending',
            'target_room_id'    => $request->filled('target_room_id') ? (int) $request->input('target_room_id') : null,
            'target_bed_id'     => $request->filled('target_bed_id') ? (int) $request->input('target_bed_id') : null,
            'target_student_id' => $request->filled('target_student_id') ? (int) $request->input('target_student_id') : null,
        ]);

        $this->notifyAdmin($student, $supportRequest);

        return response()->json(
            (new StudentSupportRequestResource($supportRequest->fresh(self::WITH_ALL)))->resolve(),
            201,
        );
    }

    private function validateTransferRequestTarget(StoreStudentSupportRequest $request, Student $requester): ?JsonResponse
    {
        $requestType = $request->string('request_type')->toString();
        $targetRoomId = $request->filled('target_room_id') ? (int) $request->input('target_room_id') : null;
        $targetBedId = $request->filled('target_bed_id') ? (int) $request->input('target_bed_id') : null;

        $currentOccupancy = $this->activeApprovedOccupancyQuery()
            ->where('student_id', $requester->id)
            ->first();

        if (! $currentOccupancy) {
            return response()->json(['message' => 'Chỉ sinh viên đang lưu trú mới được gửi yêu cầu đổi phòng/giường.'], 422);
        }

        if (! $targetBedId) {
            return response()->json(['message' => 'Vui lòng chọn giường đích.'], 422);
        }

        $targetBed = Bed::query()->find($targetBedId);

        if (! $targetBed || strtolower((string) $targetBed->status) !== 'active') {
            return response()->json(['message' => 'Giường đích không hợp lệ hoặc đang bảo trì.'], 422);
        }

        $targetRoom = Room::query()->with('floor')->find($targetBed->room_id);
        if (! $targetRoom || strtolower((string) $targetRoom->status) === 'maintenance') {
            return response()->json(['message' => 'Phòng đích không hợp lệ hoặc đang bảo trì.'], 422);
        }

        $targetFloorGender = $targetRoom->floor?->gender;
        if ($targetFloorGender && $targetFloorGender !== $requester->gender) {
            $genderLabel = $requester->gender === 'male' ? 'nam' : 'nữ';
            return response()->json(['message' => "Bạn chỉ có thể đổi sang phòng dành cho {$genderLabel}."], 422);
        }

        if ((int) $targetBed->id === (int) $currentOccupancy->bed_id) {
            return response()->json(['message' => 'Giường đích phải khác giường hiện tại.'], 422);
        }

        if ($requestType === 'room_change') {
            if (! $targetRoomId) {
                return response()->json(['message' => 'Vui lòng chọn phòng đích.'], 422);
            }

            if ((int) $targetBed->room_id !== $targetRoomId) {
                return response()->json(['message' => 'Giường đích không thuộc phòng đã chọn.'], 422);
            }

            if ((int) $targetRoomId === (int) $currentOccupancy->room_id) {
                return response()->json(['message' => 'Phòng đích phải khác phòng hiện tại.'], 422);
            }
        }

        if ($requestType === 'bed_change' && (int) $targetBed->room_id !== (int) $currentOccupancy->room_id) {
            return response()->json(['message' => 'Đổi giường chỉ được chọn giường trong phòng hiện tại.'], 422);
        }

        if ($requestType === 'roommate_request') {
            $roommateError = $this->validateRoommateRequestTarget($request, $requester, $targetBed, $currentOccupancy);
            if ($roommateError) {
                return $roommateError;
            }
        }

        $alreadyOccupied = Occupancy::occupiedBedsQuery()
            ->where('bed_id', $targetBedId)
            ->exists();

        if ($alreadyOccupied) {
            return response()->json(['message' => 'Giường đích đã có sinh viên khác ở hoặc đang được giữ chỗ.'], 422);
        }

        return null;
    }

    private function validateRoommateRequestTarget(
        StoreStudentSupportRequest $request,
        Student $requester,
        Bed $targetBed,
        Occupancy $currentOccupancy,
    ): ?JsonResponse
    {
        $targetStudentId = (int) $request->input('target_student_id');
        $targetRoomId = (int) $request->input('target_room_id');

        if (! $targetStudentId || ! $targetRoomId) {
            return response()->json(['message' => 'Vui lòng chọn sinh viên, phòng và giường muốn ở cùng.'], 422);
        }

        if ($targetStudentId === (int) $requester->id) {
            return response()->json(['message' => 'Không thể gửi yêu cầu ở cùng chính mình.'], 422);
        }

        $targetOccupancy = $this->activeApprovedOccupancyQuery()
            ->where('student_id', $targetStudentId)
            ->first();

        if (! $targetOccupancy || (int) $targetOccupancy->room_id !== $targetRoomId) {
            return response()->json(['message' => 'Sinh viên muốn ở cùng không còn ở phòng đã chọn.'], 422);
        }

        if ((int) $targetBed->room_id !== $targetRoomId) {
            return response()->json(['message' => 'Giường muốn chọn không hợp lệ trong phòng của sinh viên đó.'], 422);
        }

        if ((int) $targetRoomId === (int) $currentOccupancy->room_id) {
            return response()->json(['message' => 'Bạn đã ở cùng phòng với sinh viên này. Vui lòng dùng yêu cầu đổi giường nếu cần đổi vị trí.'], 422);
        }

        return null;
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = StudentSupportRequest::query()
            ->with(self::WITH_ALL)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('request_type')) {
            $query->where('request_type', $request->query('request_type'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->whereHas('student', function ($studentQuery) use ($search) {
                $studentQuery
                    ->where('student_code', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(StudentSupportRequestResource::collection($query->get())->resolve());
    }

    public function adminShow(int $id): JsonResponse
    {
        $supportRequest = StudentSupportRequest::query()
            ->with(self::WITH_ALL)
            ->findOrFail($id);

        return response()->json((new StudentSupportRequestResource($supportRequest))->resolve());
    }

    public function process(ProcessStudentSupportRequest $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'processing');
    }

    public function approve(ProcessStudentSupportRequest $request, int $id): JsonResponse
    {
        $supportRequest = StudentSupportRequest::query()
            ->with(self::WITH_ALL)
            ->findOrFail($id);

        if (in_array($supportRequest->request_type, ['room_change', 'bed_change', 'roommate_request'], true)) {
            return $this->executeAutoTransfer($request, $supportRequest);
        }

        return $this->transition($request, $id, 'approved');
    }

    public function reject(ProcessStudentSupportRequest $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'rejected');
    }

    public function complete(ProcessStudentSupportRequest $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'completed');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status'     => ['required', 'in:pending,processing,rejected,completed'],
            'admin_note' => ['nullable', 'string'],
        ]);

        $supportRequest = StudentSupportRequest::query()->findOrFail($id);

        $supportRequest->update([
            'status'     => $validated['status'],
            'admin_note' => array_key_exists('admin_note', $validated)
                ? $validated['admin_note']
                : $supportRequest->admin_note,
        ]);

        return response()->json(
            (new StudentSupportRequestResource($supportRequest->fresh(self::WITH_ALL)))->resolve(),
        );
    }

    // ── Auto-transfer logic ────────────────────────────────────────────────────

    private function activeApprovedOccupancyQuery(): Builder
    {
        return Occupancy::query()
            ->where('status', 'ACTIVE')
            ->where('bed_approval_status', 'approved')
            ->whereNotNull('bed_id');
    }

    private function validateSupportTransferTargetForApproval(
        StudentSupportRequest $supportRequest,
        Occupancy $currentOccupancy,
        Bed $targetBed,
    ): ?JsonResponse {
        if (strtolower((string) $targetBed->status) !== 'active') {
            return response()->json(['message' => 'Giường đích không hợp lệ hoặc đang bảo trì.'], 422);
        }

        if (! $targetBed->room || strtolower((string) $targetBed->room->status) === 'maintenance') {
            return response()->json(['message' => 'Phòng đích không hợp lệ hoặc đang bảo trì.'], 422);
        }

        if ((int) $targetBed->id === (int) $currentOccupancy->bed_id) {
            return response()->json(['message' => 'Giường đích phải khác giường hiện tại.'], 422);
        }

        if ($supportRequest->request_type === 'room_change') {
            if (! $supportRequest->target_room_id) {
                return response()->json(['message' => 'Yêu cầu đổi phòng chưa có phòng đích.'], 422);
            }

            if ((int) $targetBed->room_id !== (int) $supportRequest->target_room_id) {
                return response()->json(['message' => 'Giường đích không thuộc phòng đã chọn.'], 422);
            }

            if ((int) $targetBed->room_id === (int) $currentOccupancy->room_id) {
                return response()->json(['message' => 'Phòng đích phải khác phòng hiện tại.'], 422);
            }
        }

        if ($supportRequest->request_type === 'bed_change' && (int) $targetBed->room_id !== (int) $currentOccupancy->room_id) {
            return response()->json(['message' => 'Đổi giường chỉ được duyệt sang giường trong phòng hiện tại.'], 422);
        }

        if ($supportRequest->request_type === 'roommate_request') {
            if (! $supportRequest->target_student_id || ! $supportRequest->target_room_id) {
                return response()->json(['message' => 'Yêu cầu bạn cùng phòng thiếu thông tin sinh viên hoặc phòng đích.'], 422);
            }

            if ((int) $supportRequest->target_student_id === (int) $currentOccupancy->student_id) {
                return response()->json(['message' => 'Không thể duyệt yêu cầu ở cùng chính sinh viên gửi đơn.'], 422);
            }

            $targetStudentOccupancy = $this->activeApprovedOccupancyQuery()
                ->where('student_id', $supportRequest->target_student_id)
                ->lockForUpdate()
                ->first();

            if (! $targetStudentOccupancy || (int) $targetStudentOccupancy->room_id !== (int) $supportRequest->target_room_id) {
                return response()->json(['message' => 'Sinh viên muốn ở cùng không còn ở phòng đã chọn.'], 422);
            }

            if ((int) $targetBed->room_id !== (int) $supportRequest->target_room_id) {
                return response()->json(['message' => 'Giường đích không thuộc phòng của sinh viên muốn ở cùng.'], 422);
            }

            if ((int) $targetBed->room_id === (int) $currentOccupancy->room_id) {
                return response()->json(['message' => 'Sinh viên gửi đơn đã ở cùng phòng với sinh viên này.'], 422);
            }
        }

        return null;
    }

    private function executeAutoTransfer(ProcessStudentSupportRequest $request, StudentSupportRequest $supportRequest): JsonResponse
    {
        $student = $supportRequest->student;

        if (! $student) {
            return response()->json(['message' => 'Không tìm thấy sinh viên.'], 422);
        }

        if (! $supportRequest->target_bed_id) {
            return response()->json(['message' => 'Yêu cầu này chưa có thông tin giường đích. Vui lòng xử lý thủ công.'], 422);
        }

        $notifData = null;
        $errorResponse = null;

        DB::transaction(function () use ($request, $supportRequest, $student, &$notifData, &$errorResponse) {
            $occupancy = $this->activeApprovedOccupancyQuery()
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if (! $occupancy) {
                $errorResponse = response()->json(['message' => 'Không tìm thấy thông tin lưu trú đang hoạt động của sinh viên.'], 422);
                return;
            }

            $targetBed = Bed::with('room.floor')
                ->where('id', $supportRequest->target_bed_id)
                ->lockForUpdate()
                ->first();

            if (! $targetBed) {
                $errorResponse = response()->json(['message' => 'Giường đích không còn tồn tại trong hệ thống.'], 422);
                return;
            }

            $targetValidationError = $this->validateSupportTransferTargetForApproval($supportRequest, $occupancy, $targetBed);
            if ($targetValidationError) {
                $errorResponse = $targetValidationError;
                return;
            }

            $blockingOccupancy = Occupancy::occupiedBedsQuery()
                ->where('bed_id', $targetBed->id)
                ->where('id', '!=', $occupancy->id)
                ->lockForUpdate()
                ->first();

            if ($blockingOccupancy) {
                $errorResponse = response()->json(['message' => 'Giường đích đã có sinh viên khác ở hoặc đang được giữ chỗ.'], 422);
                return;
            }

            $oldRoomId = (int) $occupancy->room_id;
            $oldBedId  = (int) $occupancy->bed_id;

            $occupancy->room_id = $targetBed->room_id;
            $occupancy->bed_id  = $targetBed->id;
            $occupancy->save();

            $reason = $this->resolveSupportTransferReason($supportRequest);

            DB::table('room_change_log')->insert([
                'occupancy_id'    => $occupancy->id,
                'old_room_id'     => $oldRoomId,
                'old_bed_id'      => $oldBedId,
                'new_room_id'     => (int) $targetBed->room_id,
                'new_bed_id'      => (int) $targetBed->id,
                'transfer_reason' => $reason,
                'change_type'     => 'PERMANENT',
                'status'          => null,
                'change_source'   => 'student_request',
                'is_temporary'    => false,
                'transferred_at'  => now(),
            ]);

            $supportRequest->update([
                'status'     => 'completed',
                'admin_note' => $request->has('admin_note') ? $request->input('admin_note') : $supportRequest->admin_note,
            ]);

            $notifData = $this->buildTransferNotifData($student, $oldRoomId, $oldBedId, $targetBed, $supportRequest->request_type);
        });

        if ($errorResponse) {
            return $errorResponse;
        }

        if ($notifData) {
            $this->dispatchTransferNotif($notifData);
        }

        return response()->json(
            (new StudentSupportRequestResource($supportRequest->fresh(self::WITH_ALL)))->resolve(),
        );
    }

    private function resolveSupportTransferReason(StudentSupportRequest $supportRequest): string
    {
        foreach (preg_split('/\R/u', (string) $supportRequest->content) ?: [] as $line) {
            $line = trim((string) $line);
            if (
                preg_match('/^Lý do\s*:\s*(.+)$/u', $line, $matches)
                || preg_match('/^Ly do\s*:\s*(.+)$/iu', $line, $matches)
            ) {
                $reason = trim((string) ($matches[1] ?? ''));
                if ($reason !== '' && $reason !== '-') {
                    return $reason;
                }
            }
        }

        return match ($supportRequest->request_type) {
            'room_change' => 'Đổi phòng theo yêu cầu sinh viên',
            'roommate_request' => 'Ở cùng bạn theo yêu cầu sinh viên',
            default => 'Đổi giường theo yêu cầu sinh viên',
        };
    }

    private function buildTransferNotifData(
        Student $student,
        int $oldRoomId,
        int $oldBedId,
        Bed $targetBed,
        string $requestType,
    ): ?array {
        $oldRoom  = Room::with('floor')->find($oldRoomId);
        $oldBed   = Bed::find($oldBedId);
        $oldCode  = ($oldRoom?->floor?->building_code ?? '') . ($oldRoom?->room_number ?? '');
        $newCode  = ($targetBed->room?->floor?->building_code ?? '') . ($targetBed->room?->room_number ?? '');
        $oldLabel = "Phòng {$oldCode}, Giường " . ($oldBed?->bed_number ?? '?');
        $newLabel = "Phòng {$newCode}, Giường {$targetBed->bed_number}";

        $title   = match ($requestType) {
            'room_change' => 'Yêu cầu đổi phòng đã được duyệt',
            'roommate_request' => 'Yêu cầu ở cùng phòng đã được duyệt',
            default => 'Yêu cầu đổi giường đã được duyệt',
        };
        $content = "Yêu cầu của bạn đã được chấp thuận. Bạn đã được chuyển từ {$oldLabel} sang {$newLabel}.";
        $type    = match ($requestType) {
            'room_change' => 'room_change_approved',
            'roommate_request' => 'roommate_request_approved',
            default => 'bed_change_approved',
        };

        try {
            $notification = Notification::create([
                'student_id'  => $student->id,
                'title'       => $title,
                'content'     => $content,
                'type'        => $type,
                'related_id'  => $supportRequest->id,
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
            'email'   => $student->email,
            'name'    => $student->full_name ?? '',
            'subject' => "KTX — {$title}",
            'title'   => $title,
            'content' => $content,
        ];
    }

    private function dispatchTransferNotif(array $data): void
    {
        if (empty($data['email'])) {
            return;
        }

        try {
            $name    = htmlspecialchars($data['name']);
            $title   = htmlspecialchars($data['title']);
            $content = nl2br(htmlspecialchars($data['content']));
            $body    = "
                <div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152;'>
                    <h2 style='color:#244cb8;margin-top:0;'>{$title}</h2>
                    <p>Xin chào <strong>{$name}</strong>,</p>
                    <p style='line-height:1.7;'>{$content}</p>
                    <p style='color:#6b7280;font-size:12px;margin-top:32px;'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
                </div>
            ";
            Mail::send([], [], function ($message) use ($data, $body) {
                $message->to($data['email'], $data['name'])
                    ->subject($data['subject'])
                    ->html($body);
            });
        } catch (\Exception) {}
    }

    // ── Shared helpers ─────────────────────────────────────────────────────────

    private function transition(ProcessStudentSupportRequest $request, int $id, string $status): JsonResponse
    {
        $supportRequest = StudentSupportRequest::query()->findOrFail($id);

        $supportRequest->update([
            'status'     => $status,
            'admin_note' => $request->has('admin_note') ? $request->input('admin_note') : $supportRequest->admin_note,
        ]);

        return response()->json(
            (new StudentSupportRequestResource($supportRequest->fresh(self::WITH_ALL)))->resolve(),
        );
    }

    private function notifyAdmin(Student $student, StudentSupportRequest $req): void
    {
        try {
            $typeLabel = $this->getTypeLabel($req->request_type);
            $title     = "Yêu cầu hỗ trợ mới — {$typeLabel}";
            $content   = "Sinh viên {$student->full_name} ({$student->student_code}) vừa gửi yêu cầu hỗ trợ loại \"{$typeLabel}\": {$req->title}.";

            AdminNotification::create([
                'title'      => $title,
                'content'    => $content,
                'type'       => 'support_request_new',
                'related_id' => $req->id,
                'created_at' => now(),
            ]);
        } catch (\Exception) {}

        try {
            $adminEmails = Account::where('role', 'admin')
                ->with('student')
                ->get()
                ->map(fn ($acc) => $acc->student?->email)
                ->push(config('auth.admin_login_email'))
                ->filter()
                ->unique()
                ->values();

            if ($adminEmails->isEmpty()) {
                return;
            }

            $typeLabel = $this->getTypeLabel($req->request_type);
            $subject   = "[KTX STU] Yêu cầu hỗ trợ mới — {$typeLabel}";
            $body      = "
                <div style='font-family:sans-serif;max-width:600px;margin:auto;padding:24px;background:#f8fbff;border-radius:12px;'>
                    <h2 style='color:#1a2d52;margin-bottom:8px;'>Yêu cầu hỗ trợ mới</h2>
                    <p style='color:#667ca8;margin-bottom:20px;'>Có một yêu cầu hỗ trợ mới cần xử lý trên hệ thống KTX.</p>
                    <table style='width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;'>
                        <tr style='background:#eef4ff;'>
                            <td style='padding:10px 16px;font-weight:bold;color:#244cb8;width:40%;'>Sinh viên</td>
                            <td style='padding:10px 16px;color:#1f3152;'>{$student->full_name} ({$student->student_code})</td>
                        </tr>
                        <tr>
                            <td style='padding:10px 16px;font-weight:bold;color:#244cb8;border-top:1px solid #e8eef8;'>Email</td>
                            <td style='padding:10px 16px;color:#1f3152;border-top:1px solid #e8eef8;'>{$student->email}</td>
                        </tr>
                        <tr style='background:#eef4ff;'>
                            <td style='padding:10px 16px;font-weight:bold;color:#244cb8;border-top:1px solid #e8eef8;'>Loại yêu cầu</td>
                            <td style='padding:10px 16px;color:#1f3152;border-top:1px solid #e8eef8;'>{$typeLabel}</td>
                        </tr>
                        <tr>
                            <td style='padding:10px 16px;font-weight:bold;color:#244cb8;border-top:1px solid #e8eef8;'>Tiêu đề</td>
                            <td style='padding:10px 16px;color:#1f3152;border-top:1px solid #e8eef8;'>{$req->title}</td>
                        </tr>
                        <tr style='background:#eef4ff;'>
                            <td style='padding:10px 16px;font-weight:bold;color:#244cb8;border-top:1px solid #e8eef8;vertical-align:top;'>Nội dung</td>
                            <td style='padding:10px 16px;color:#1f3152;border-top:1px solid #e8eef8;'>{$req->content}</td>
                        </tr>
                    </table>
                    <p style='margin-top:20px;color:#7c8fb5;font-size:13px;'>Vui lòng đăng nhập hệ thống để xem và xử lý yêu cầu.</p>
                </div>
            ";

            foreach ($adminEmails as $email) {
                try {
                    Mail::send([], [], function ($message) use ($email, $subject, $body) {
                        $message->to($email)
                            ->subject($subject)
                            ->html($body);
                    });
                } catch (\Exception) {}
            }
        } catch (\Exception) {}
    }

    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            'room_change'      => 'Đổi phòng',
            'bed_change'       => 'Đổi giường',
            'roommate_request' => 'Bạn cùng phòng',
            'extension'        => 'Gia hạn lưu trú',
            'other'            => 'Khác',
            default            => $type,
        };
    }

    private function resolveStudent(Request $request): ?Student
    {
        if ($request->filled('student_id')) {
            return Student::query()->find((int) $request->input('student_id'));
        }

        $email = trim((string) ($request->input('email') ?: $request->query('email', '')));

        if ($email === '') {
            return null;
        }

        return Student::query()->where('email', $email)->first();
    }
}
