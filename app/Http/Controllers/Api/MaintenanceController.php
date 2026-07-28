<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\GenericNotificationMail;
use App\Models\Account;
use App\Models\AdminNotification;
use App\Models\Bed;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\Occupancy;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MaintenanceController extends Controller
{
    public function bedPlan(int $roomId, int $bedId): JsonResponse
    {
        $room = Room::query()->with(['floor', 'beds'])->findOrFail($roomId);
        $sourceBed = $room->beds->firstWhere('id', $bedId);

        if (!$sourceBed) {
            return response()->json(['message' => 'Không tìm thấy giường.'], 404);
        }

        $occupancy = $this->activeOccupancyQuery()
            ->where('bed_id', $sourceBed->id)
            ->with(['student', 'room.floor', 'bed'])
            ->first();

        return response()->json([
            'source' => $this->formatSource($room, $sourceBed, $occupancy),
            'options' => $occupancy ? $this->availableBedOptions($room)->values()->all() : [],
        ]);
    }

    public function startBedMaintenance(Request $request, int $roomId, int $bedId): JsonResponse
    {
        $data = $request->validate([
            'target_bed_id' => ['required', 'integer', 'exists:beds,id'],
            'expected_end_at' => ['nullable', 'date'],
        ]);

        $pendingEmails = [];

        $updatedRoom = DB::transaction(function () use ($roomId, $bedId, $data, &$pendingEmails) {
            $room = Room::query()->with(['floor'])->lockForUpdate()->findOrFail($roomId);
            $sourceBed = Bed::query()->where('room_id', $room->id)->where('id', $bedId)->lockForUpdate()->first();

            if (!$sourceBed) {
                abort(response()->json(['message' => 'Không tìm thấy giường nguồn.'], 404));
            }

            $targetBed = Bed::query()->with('room.floor')->where('id', (int) $data['target_bed_id'])->lockForUpdate()->firstOrFail();
            $this->assertTargetBedAvailable($targetBed);
            $this->assertTargetGenderMatches($targetBed, $room);

            $occupancy = $this->activeOccupancyQuery()
                ->where('bed_id', $sourceBed->id)
                ->with('student')
                ->lockForUpdate()
                ->first();

            if (!$occupancy) {
                abort(response()->json(['message' => 'Giường này không có sinh viên ACTIVE để chuyển tạm.'], 422));
            }

            $oldRoomId = (int) $occupancy->room_id;
            $oldBedId = (int) $occupancy->bed_id;
            $maintenanceRequest = MaintenanceRequest::create([
                'type' => 'BED',
                'room_id' => $room->id,
                'bed_id' => $sourceBed->id,
                'reason' => 'BED_MAINTENANCE',
                'status' => 'IN_PROGRESS',
                'started_at' => now(),
                'expected_end_at' => $data['expected_end_at'] ?? null,
            ]);

            // Clear stale non-active references to the target bed before reassigning.
            DB::table('occupancy')
                ->where('bed_id', $targetBed->id)
                ->where('id', '!=', $occupancy->id)
                ->whereNotIn('status', Occupancy::OCCUPIED_BED_STATUSES)
                ->update(['bed_id' => null]);

            $occupancy->room_id = $targetBed->room_id;
            $occupancy->bed_id = $targetBed->id;
            $occupancy->save();

            $sourceBed->status = 'maintenance';
            $sourceBed->save();

            $targetBed->status = 'active';
            $targetBed->save();

            $this->insertRoomChangeLog(
                $occupancy,
                $oldRoomId,
                $oldBedId,
                (int) $targetBed->room_id,
                (int) $targetBed->id,
                'BED_MAINTENANCE',
                'TEMPORARY_MAINTENANCE',
                true,
                $maintenanceRequest->id,
                'ACTIVE',
            );

            // Notification
            $student = $occupancy->student;
            if ($student) {
                $oldRoomCode = ($room->floor?->building_code ?? '') . $room->room_number;
                $newRoomCode = ($targetBed->room?->floor?->building_code ?? '') . ($targetBed->room?->room_number ?? '');
                $title   = 'Chuyển giường tạm thời — Bảo trì';
                $content = "Giường {$sourceBed->bed_number} (Phòng {$oldRoomCode}) đang được bảo trì. "
                         . "Bạn được chuyển tạm sang Giường {$targetBed->bed_number} (Phòng {$newRoomCode}).";
                $this->createNotifRecord($student->id, $title, $content, 'bed_transferred_temporary');
                $pendingEmails[] = $this->buildEmailPayload($student, $title, $content);
            }

            return $room->fresh(['floor', 'beds']);
        });

        foreach ($pendingEmails as $emailData) {
            $this->sendNotificationEmail($emailData);
        }

        return response()->json($this->formatRoom($updatedRoom));
    }

    public function completeBedMaintenance(int $roomId, int $bedId): JsonResponse
    {
        $pendingEmails = [];

        $updatedRoom = DB::transaction(function () use ($roomId, $bedId, &$pendingEmails) {
            $room = Room::query()->with(['floor'])->lockForUpdate()->findOrFail($roomId);
            $sourceBed = Bed::query()->where('room_id', $room->id)->where('id', $bedId)->lockForUpdate()->first();

            if (!$sourceBed) {
                abort(response()->json(['message' => 'Không tìm thấy giường bảo trì.'], 404));
            }

            $log = DB::table('room_change_log')
                ->where('old_bed_id', $sourceBed->id)
                ->where('change_type', 'TEMPORARY_MAINTENANCE')
                ->where('status', 'ACTIVE')
                ->where(function ($query) {
                    $query->where('transfer_reason', 'BED_MAINTENANCE')
                        ->orWhereExists(function ($subQuery) {
                            $subQuery->select(DB::raw(1))
                                ->from('maintenance_requests')
                                ->whereColumn('maintenance_requests.id', 'room_change_log.maintenance_request_id')
                                ->where('maintenance_requests.type', 'BED');
                        });
                })
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$log) {
                abort(response()->json(['message' => 'Không tìm thấy lịch sử chuyển tạm của giường này.'], 422));
            }

            $occupancy = $this->activeOccupancyQuery()
                ->where('id', $log->occupancy_id)
                ->with('student')
                ->lockForUpdate()
                ->first();

            if (!$occupancy) {
                abort(response()->json(['message' => 'Không tìm thấy sinh viên cần chuyển về.'], 422));
            }

            $temporaryBed = Bed::query()->where('id', $occupancy->bed_id)->lockForUpdate()->first();
            $this->assertReturnBedAvailable((int) $log->old_bed_id, (int) $occupancy->id);

            $currentRoomId = (int) $occupancy->room_id;
            $currentBedId = (int) $occupancy->bed_id;

            $occupancy->room_id = (int) $log->old_room_id;
            $occupancy->bed_id = (int) $log->old_bed_id;
            $occupancy->save();

            if ($temporaryBed) {
                $temporaryBed->status = 'active';
                $temporaryBed->save();
            }

            $sourceBed->status = 'active';
            $sourceBed->save();

            $this->insertRoomChangeLog(
                $occupancy,
                $currentRoomId,
                $currentBedId,
                (int) $log->old_room_id,
                (int) $log->old_bed_id,
                'BED_MAINTENANCE_RETURN',
                'TEMPORARY_MAINTENANCE',
                false,
                $log->maintenance_request_id ? (int) $log->maintenance_request_id : null,
                'RETURNED',
            );
            DB::table('room_change_log')->where('id', $log->id)->update(['status' => 'RETURNED', 'completed_at' => now()]);
            if ($log->maintenance_request_id) {
                MaintenanceRequest::query()
                    ->where('id', $log->maintenance_request_id)
                    ->update(['status' => 'COMPLETED', 'completed_at' => now()]);
            }

            // Notification
            $student = $occupancy->student;
            if ($student) {
                $oldRoomCode = ($room->floor?->building_code ?? '') . $room->room_number;
                $title   = 'Bảo trì hoàn tất — Đã trả về giường cũ';
                $content = "Bảo trì giường đã hoàn tất. Bạn đã được chuyển về Giường {$sourceBed->bed_number} (Phòng {$oldRoomCode}).";
                $this->createNotifRecord($student->id, $title, $content, 'bed_maintenance_return');
                $pendingEmails[] = $this->buildEmailPayload($student, $title, $content);
            }

            return $room->fresh(['floor', 'beds']);
        });

        foreach ($pendingEmails as $emailData) {
            $this->sendNotificationEmail($emailData);
        }

        return response()->json($this->formatRoom($updatedRoom));
    }

    public function roomPlan(int $roomId): JsonResponse
    {
        $room = Room::query()->with(['floor', 'beds'])->findOrFail($roomId);
        $occupancies = $this->activeOccupancyQuery()
            ->where('room_id', $room->id)
            ->with(['student', 'registration', 'bed', 'room.floor'])
            ->orderBy('bed_id')
            ->get();

        $options = $this->availableBedOptions($room, true)->values();
        $cursor = 0;
        $assignments = $occupancies->map(function (Occupancy $occupancy) use ($options, &$cursor) {
            $target = $options->get($cursor);
            $cursor++;

            return [
                'occupancy_id' => $occupancy->id,
                'student' => $this->formatStudent($occupancy),
                'current_room' => $this->roomCode($occupancy->room),
                'current_bed' => (string) $occupancy->bed?->bed_number,
                'target' => $target,
            ];
        });

        return response()->json([
            'room' => $this->formatRoomHeader($room),
            'affected' => $assignments->all(),
            'options' => $options->all(),
            'can_start' => $occupancies->count() <= $options->count(),
        ]);
    }

    public function getRequestRoom(int $requestId): JsonResponse
    {
        $maintenanceRequest = MaintenanceRequest::query()->findOrFail($requestId);
        $room = Room::query()->with('floor')->findOrFail($maintenanceRequest->room_id);

        return response()->json([
            'room_id'   => $room->id,
            'room_code' => $this->roomCode($room),
        ]);
    }

    public function startRoomMaintenance(Request $request, int $roomId): JsonResponse
    {
        $data = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.occupancy_id' => ['required', 'integer', 'exists:occupancy,id'],
            'assignments.*.target_bed_id' => ['required', 'integer', 'exists:beds,id'],
            'reason' => ['required', 'string', 'max:500'],
            'started_at' => ['required', 'date'],
            'expected_end_at' => ['required', 'date', 'after_or_equal:started_at'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $isFuture = Carbon::parse($data['started_at'])->startOfDay()->gt(Carbon::today());

        if ($isFuture) {
            return $this->schedulePendingRoomMaintenance($roomId, $data);
        }

        return $this->executeRoomMaintenance($roomId, $data);
    }

    private function schedulePendingRoomMaintenance(int $roomId, array $data): JsonResponse
    {
        $room = Room::query()->with(['floor', 'beds'])->findOrFail($roomId);
        $targets = collect($data['assignments'])->pluck('target_bed_id')->map(fn ($id) => (int) $id);

        if ($targets->unique()->count() !== $targets->count()) {
            return response()->json(['message' => 'Mỗi sinh viên phải có một giường đích khác nhau.'], 422);
        }

        $targetBeds = Bed::query()->with('room.floor')->whereIn('id', $targets->all())->get()->keyBy('id');
        foreach ($targets as $targetBedId) {
            $targetBed = $targetBeds->get($targetBedId);
            if (! $targetBed) {
                return response()->json(['message' => 'Không tìm thấy giường đích.'], 404);
            }
            if ((int) $targetBed->room_id === (int) $room->id) {
                return response()->json(['message' => 'Giường đích không được nằm trong phòng đang bảo trì.'], 422);
            }
            $this->assertTargetBedAvailable($targetBed);
            $this->assertTargetGenderMatches($targetBed, $room);
        }

        $startDate   = Carbon::parse($data['started_at'])->format('d/m/Y');
        $expectedEnd = Carbon::parse($data['expected_end_at'])->format('d/m/Y');
        $roomCode    = ($room->floor?->building_code ?? '') . $room->room_number;

        $pendingEmails = [];

        DB::transaction(function () use ($room, $data, $targetBeds, $startDate, $expectedEnd, $roomCode, &$pendingEmails) {
            $maintenanceRequest = MaintenanceRequest::create([
                'type'                => 'ROOM',
                'room_id'             => $room->id,
                'reason'              => trim($data['reason']),
                'note'                => $data['note'] ?? null,
                'pending_assignments' => $data['assignments'],
                'status'              => 'PENDING',
                'started_at'          => $data['started_at'],
                'expected_end_at'     => $data['expected_end_at'],
            ]);

            // Thông báo trước cho sinh viên rằng phòng sẽ bảo trì
            foreach ($data['assignments'] as $assignment) {
                $occupancy = $this->activeOccupancyQuery()
                    ->where('id', (int) $assignment['occupancy_id'])
                    ->where('room_id', $room->id)
                    ->with(['student', 'bed'])
                    ->first();

                if (! $occupancy) {
                    continue;
                }

                $targetBed  = $targetBeds->get((int) $assignment['target_bed_id']);
                $newRoomCode = $targetBed
                    ? ($targetBed->room?->floor?->building_code ?? '') . ($targetBed->room?->room_number ?? '')
                    : '(chưa xác định)';
                $oldBedNum = $occupancy->bed?->bed_number ?? '';

                $student = $occupancy->student;
                if ($student) {
                    $title   = 'Thông báo bảo trì phòng — Lịch di dời';
                    $content = "Phòng {$roomCode} sẽ được bảo trì từ ngày {$startDate} đến {$expectedEnd}. "
                             . "Bạn sẽ được chuyển tạm từ Giường {$oldBedNum} (Phòng {$roomCode}) "
                             . "sang Giường {$targetBed?->bed_number} (Phòng {$newRoomCode}) vào ngày {$startDate}.";
                    $this->createNotifRecord($student->id, $title, $content, 'room_maintenance_scheduled');
                    $pendingEmails[] = $this->buildEmailPayload($student, $title, $content);
                }
            }

            // Thông báo admin
            $adminTitle   = "Lịch bảo trì phòng {$roomCode}";
            $adminContent = "Phòng {$roomCode} đã được lên lịch bảo trì từ {$startDate} đến {$expectedEnd}. "
                          . "Hệ thống sẽ tự động di dời sinh viên vào ngày bắt đầu.";
            $this->createAdminNotif($adminTitle, $adminContent, 'room_maintenance_scheduled', $maintenanceRequest->id);
            $pendingEmails[] = $this->buildAdminEmailPayload($adminTitle, $adminContent);
        });

        // Có thể là toàn bộ người ở phòng + admin — queue để không chặn request.
        foreach ($pendingEmails as $emailData) {
            $this->sendNotificationEmail($emailData, queue: true);
        }

        return response()->json($this->formatRoom($room->fresh(['floor', 'beds'])));
    }

    public function executeRoomMaintenance(int $roomId, array $data): JsonResponse
    {
        $pendingEmails = [];

        $updatedRoom = DB::transaction(function () use ($roomId, $data, &$pendingEmails) {
            $room = Room::query()->with(['floor', 'beds'])->lockForUpdate()->findOrFail($roomId);
            $targets = collect($data['assignments'])->pluck('target_bed_id')->map(fn ($id) => (int) $id);

            if ($targets->unique()->count() !== $targets->count()) {
                abort(response()->json(['message' => 'Mỗi sinh viên phải có một giường đích khác nhau.'], 422));
            }

            $targetBeds = Bed::query()->with('room.floor')->whereIn('id', $targets->all())->lockForUpdate()->get()->keyBy('id');
            foreach ($targets as $targetBedId) {
                $targetBed = $targetBeds->get($targetBedId);
                if (!$targetBed) {
                    abort(response()->json(['message' => 'Không tìm thấy giường đích.'], 404));
                }
                if ((int) $targetBed->room_id === (int) $room->id) {
                    abort(response()->json(['message' => 'Giường đích không được nằm trong phòng đang bảo trì.'], 422));
                }
                $this->assertTargetBedAvailable($targetBed);
                $this->assertTargetGenderMatches($targetBed, $room);
            }

            // Tìm hoặc tạo maintenance request (có thể đã PENDING)
            $maintenanceRequest = MaintenanceRequest::query()
                ->where('room_id', $room->id)
                ->where('type', 'ROOM')
                ->where('status', 'PENDING')
                ->latest()
                ->first();

            if ($maintenanceRequest) {
                $maintenanceRequest->update([
                    'status'              => 'IN_PROGRESS',
                    'pending_assignments' => null,
                ]);
            } else {
                $maintenanceRequest = MaintenanceRequest::create([
                    'type'            => 'ROOM',
                    'room_id'         => $room->id,
                    'reason'          => trim($data['reason']),
                    'note'            => $data['note'] ?? null,
                    'status'          => 'IN_PROGRESS',
                    'started_at'      => $data['started_at'],
                    'expected_end_at' => $data['expected_end_at'],
                ]);
            }

            foreach ($data['assignments'] as $assignment) {
                $occupancy = $this->activeOccupancyQuery()
                    ->where('id', (int) $assignment['occupancy_id'])
                    ->where('room_id', $room->id)
                    ->with(['student', 'bed'])
                    ->lockForUpdate()
                    ->first();

                if (!$occupancy) {
                    abort(response()->json(['message' => 'Danh sách sinh viên chuyển phòng không hợp lệ.'], 422));
                }

                $targetBed = $targetBeds->get((int) $assignment['target_bed_id']);
                $oldRoomId = (int) $occupancy->room_id;
                $oldBedId = (int) $occupancy->bed_id;
                $oldBedNum = $occupancy->bed?->bed_number ?? $oldBedId;

                DB::table('occupancy')
                    ->where('bed_id', $targetBed->id)
                    ->where('id', '!=', $occupancy->id)
                    ->whereNotIn('status', Occupancy::OCCUPIED_BED_STATUSES)
                    ->update(['bed_id' => null]);

                $occupancy->room_id = $targetBed->room_id;
                $occupancy->bed_id = $targetBed->id;
                $occupancy->save();

                $targetBed->status = 'active';
                $targetBed->save();

                if ($occupancy->bed) {
                    $occupancy->bed->status = 'maintenance';
                    $occupancy->bed->save();
                }

                $this->insertRoomChangeLog(
                    $occupancy,
                    $oldRoomId,
                    $oldBedId,
                    (int) $targetBed->room_id,
                    (int) $targetBed->id,
                    'ROOM_MAINTENANCE',
                    'TEMPORARY_MAINTENANCE',
                    true,
                    $maintenanceRequest->id,
                    'ACTIVE',
                    $data['expected_end_at'],
                    $data['started_at'],
                );

                $student = $occupancy->student;
                if ($student) {
                    $oldRoomCode = ($room->floor?->building_code ?? '') . $room->room_number;
                    $newRoomCode = ($targetBed->room?->floor?->building_code ?? '') . ($targetBed->room?->room_number ?? '');
                    $expectedEnd = Carbon::parse($data['expected_end_at'])->format('d/m/Y');
                    $title   = 'Chuyển phòng tạm thời — Bảo trì phòng';
                    $content = "Phòng {$oldRoomCode} đang được bảo trì (dự kiến đến {$expectedEnd}). "
                             . "Bạn được chuyển tạm từ Giường {$oldBedNum} (Phòng {$oldRoomCode}) "
                             . "sang Giường {$targetBed->bed_number} (Phòng {$newRoomCode}).";
                    $this->createNotifRecord($student->id, $title, $content, 'room_maintenance_start');
                    $pendingEmails[] = $this->buildEmailPayload($student, $title, $content);
                }
            }

            // Đảm bảo assignments đã bao phủ hết mọi occupancy ACTIVE còn lại trong phòng —
            // tránh set bed maintenance cho giường vẫn còn sinh viên ACTIVE chưa được chuyển tạm.
            $assignedOccupancyIds = collect($data['assignments'])->pluck('occupancy_id')->map(fn ($id) => (int) $id);
            $unhandledActiveOccupancy = $this->activeOccupancyQuery()
                ->where('room_id', $room->id)
                ->whereNotIn('id', $assignedOccupancyIds->all())
                ->with('student')
                ->lockForUpdate()
                ->first();

            if ($unhandledActiveOccupancy) {
                $studentLabel = $unhandledActiveOccupancy->student
                    ? "{$unhandledActiveOccupancy->student->full_name} ({$unhandledActiveOccupancy->student->student_code})"
                    : "occupancy #{$unhandledActiveOccupancy->id}";
                abort(response()->json([
                    'message' => "Còn sinh viên {$studentLabel} chưa được chuyển tạm, vui lòng bổ sung đầy đủ trước khi bắt đầu bảo trì.",
                ], 422));
            }

            $room->status = 'maintenance';
            $room->save();
            $room->beds()->update(['status' => 'maintenance']);

            return $room->fresh(['floor', 'beds']);
        });

        // Toàn bộ người ở phòng đang bị chuyển tạm — queue để không chặn request.
        foreach ($pendingEmails as $emailData) {
            $this->sendNotificationEmail($emailData, queue: true);
        }

        return response()->json($this->formatRoom($updatedRoom));
    }

    public function completeRoomMaintenance(int $roomId): JsonResponse
    {
        $pendingEmails = [];

        $updatedRoom = DB::transaction(function () use ($roomId, &$pendingEmails) {
            $room = Room::query()->with(['floor', 'beds'])->lockForUpdate()->findOrFail($roomId);
            $logs = DB::table('room_change_log')
                ->where('old_room_id', $room->id)
                ->where('change_type', 'TEMPORARY_MAINTENANCE')
                ->where('transfer_reason', 'ROOM_MAINTENANCE')
                ->where('status', 'ACTIVE')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($logs as $log) {
                $this->returnRoomMaintenanceLog($log, $pendingEmails);
            }

            $requestIds = $logs->pluck('maintenance_request_id')->filter()->unique()->values()->all();
            if (!$requestIds) {
                $requestIds = MaintenanceRequest::query()
                    ->where('room_id', $room->id)
                    ->where('type', 'ROOM')
                    ->where('status', 'IN_PROGRESS')
                    ->pluck('id')
                    ->unique()
                    ->values()
                    ->all();
            }
            if ($requestIds) {
                MaintenanceRequest::query()
                    ->whereIn('id', $requestIds)
                    ->update(['status' => 'COMPLETED', 'completed_at' => now()]);
            }

            $room->status = 'active';
            $room->save();
            $this->syncRoomBedStatuses($room);

            return $room->fresh(['floor', 'beds']);
        });

        // Toàn bộ log chuyển phòng liên quan (nhiều sinh viên) — queue để không chặn request.
        foreach ($pendingEmails as $emailData) {
            $this->sendNotificationEmail($emailData, queue: true);
        }

        return response()->json($this->formatRoom($updatedRoom));
    }

    public function completeRoomMaintenanceStudent(int $roomId, int $occupancyId): JsonResponse
    {
        $pendingEmails = [];

        $updatedRoom = DB::transaction(function () use ($roomId, $occupancyId, &$pendingEmails) {
            $room = Room::query()->with(['floor', 'beds'])->lockForUpdate()->findOrFail($roomId);
            $log = DB::table('room_change_log')
                ->where('old_room_id', $room->id)
                ->where('occupancy_id', $occupancyId)
                ->where('change_type', 'TEMPORARY_MAINTENANCE')
                ->where('transfer_reason', 'ROOM_MAINTENANCE')
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if (!$log) {
                abort(response()->json(['message' => 'Không tìm thấy sinh viên đang điều chuyển tạm của phòng này.'], 422));
            }

            $this->returnRoomMaintenanceLog($log, $pendingEmails);

            $remaining = DB::table('room_change_log')
                ->where('old_room_id', $room->id)
                ->where('change_type', 'TEMPORARY_MAINTENANCE')
                ->where('transfer_reason', 'ROOM_MAINTENANCE')
                ->where('status', 'ACTIVE')
                ->exists();

            if (!$remaining) {
                $requestIds = DB::table('room_change_log')
                    ->where('old_room_id', $room->id)
                    ->where('transfer_reason', 'ROOM_MAINTENANCE')
                    ->whereNotNull('maintenance_request_id')
                    ->pluck('maintenance_request_id')
                    ->unique()
                    ->values()
                    ->all();

                if ($requestIds) {
                    MaintenanceRequest::query()
                        ->whereIn('id', $requestIds)
                        ->update(['status' => 'COMPLETED', 'completed_at' => now()]);
                }

                $room->status = 'active';
                $room->save();
                $this->syncRoomBedStatuses($room);
            }

            return $room->fresh(['floor', 'beds']);
        });

        foreach ($pendingEmails as $emailData) {
            $this->sendNotificationEmail($emailData);
        }

        return response()->json($this->formatRoom($updatedRoom));
    }

    private function activeOccupancyQuery()
    {
        return Occupancy::occupiedBedsQuery();
    }

    private function availableBedOptions(Room $sourceRoom, bool $excludeSourceRoom = false): Collection
    {
        $sourceRoom->loadMissing('floor');
        $occupiedBedIds = $this->activeOccupancyQuery()->pluck('bed_id')->map(fn ($id) => (int) $id)->all();

        return Bed::query()
            ->with('room.floor')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereRaw('LOWER(status) != ?', ['maintenance']);
            })
            ->when($excludeSourceRoom, fn ($query) => $query->where('room_id', '!=', $sourceRoom->id))
            ->whereNotIn('id', $occupiedBedIds)
            ->get()
            ->filter(function (Bed $bed) use ($sourceRoom) {
                $room = $bed->room;
                $floor = $room?->floor;
                if (!$room || !$floor || strtolower((string) $room->status) === 'maintenance') {
                    return false;
                }

                return strtolower((string) $floor->gender) === strtolower((string) $sourceRoom->floor?->gender);
            })
            ->sortBy(fn (Bed $bed) => sprintf(
                '%d|%s|%04d|%s|%04d',
                $this->bedPriority($bed, $sourceRoom),
                (string) $bed->room?->floor?->building_code,
                (int) $bed->room?->floor?->floor_number,
                (string) $bed->room?->room_number,
                (int) $bed->bed_number,
            ))
            ->map(fn (Bed $bed) => $this->formatBedOption($bed, $sourceRoom));
    }

    private function bedPriority(Bed $bed, Room $sourceRoom): int
    {
        if ((int) $bed->room_id === (int) $sourceRoom->id) {
            return 0;
        }
        if ((int) $bed->room?->floor_id === (int) $sourceRoom->floor_id) {
            return 1;
        }
        if ((string) $bed->room?->floor?->building_code === (string) $sourceRoom->floor?->building_code) {
            return 2;
        }
        return 3;
    }

    private function assertTargetBedAvailable(Bed $bed): void
    {
        $bed->loadMissing('room.floor');
        if (strtolower((string) $bed->status) === 'maintenance' || strtolower((string) $bed->room?->status) === 'maintenance') {
            abort(response()->json(['message' => 'Giường đích không khả dụng.'], 422));
        }

        $occupied = $this->activeOccupancyQuery()->where('bed_id', $bed->id)->exists();
        if ($occupied) {
            abort(response()->json(['message' => 'Giường đích đã bị chiếm.'], 422));
        }
    }

    private function assertTargetGenderMatches(Bed $bed, Room $sourceRoom): void
    {
        $bed->loadMissing('room.floor');
        $sourceRoom->loadMissing('floor');

        if (strtolower((string) $bed->room?->floor?->gender) !== strtolower((string) $sourceRoom->floor?->gender)) {
            abort(response()->json(['message' => 'Giường đích không cùng giới tính với phòng nguồn.'], 422));
        }
    }

    private function assertReturnBedAvailable(int $bedId, int $occupancyId): void
    {
        $occupied = $this->activeOccupancyQuery()
            ->where('bed_id', $bedId)
            ->where('id', '!=', $occupancyId)
            ->exists();

        if ($occupied) {
            abort(response()->json(['message' => 'Giường cũ đã có sinh viên khác ở, không thể hoàn tất bảo trì.'], 422));
        }
    }

    private function returnRoomMaintenanceLog(object $log, array &$pendingEmails = []): void
    {
        $occupancy = $this->activeOccupancyQuery()
            ->where('id', $log->occupancy_id)
            ->with('student')
            ->lockForUpdate()
            ->first();

        if (!$occupancy) {
            abort(response()->json(['message' => 'Không tìm thấy sinh viên cần chuyển về phòng cũ.'], 422));
        }

        $this->assertReturnBedAvailable((int) $log->old_bed_id, (int) $occupancy->id);
        $temporaryBed = Bed::query()->where('id', $occupancy->bed_id)->lockForUpdate()->first();
        $oldBed = Bed::query()->where('id', $log->old_bed_id)->lockForUpdate()->firstOrFail();
        $currentRoomId = (int) $occupancy->room_id;
        $currentBedId = (int) $occupancy->bed_id;

        $occupancy->room_id = (int) $log->old_room_id;
        $occupancy->bed_id = (int) $log->old_bed_id;
        $occupancy->save();

        if ($temporaryBed) {
            $temporaryBed->status = 'active';
            $temporaryBed->save();
        }

        $oldBed->status = 'active';
        $oldBed->save();

        $this->insertRoomChangeLog(
            $occupancy,
            $currentRoomId,
            $currentBedId,
            (int) $log->old_room_id,
            (int) $log->old_bed_id,
            'ROOM_MAINTENANCE_RETURN',
            'TEMPORARY_MAINTENANCE',
            false,
            $log->maintenance_request_id ? (int) $log->maintenance_request_id : null,
            'RETURNED',
            null,
            now(),
        );
        DB::table('room_change_log')->where('id', $log->id)->update(['status' => 'RETURNED', 'completed_at' => now()]);

        // Notification sinh viên
        $student = $occupancy->student;
        if ($student) {
            $oldRoom = Room::with('floor')->find($log->old_room_id);
            $oldRoomCode = ($oldRoom?->floor?->building_code ?? '') . ($oldRoom?->room_number ?? '');
            $title   = 'Bảo trì hoàn tất — Đã trả về phòng cũ';
            $content = "Bảo trì phòng đã hoàn tất. Bạn đã được chuyển về Giường {$oldBed->bed_number} (Phòng {$oldRoomCode}).";
            $this->createNotifRecord($student->id, $title, $content, 'room_maintenance_return');
            $pendingEmails[] = $this->buildEmailPayload($student, $title, $content);

            // Notification admin
            $adminTitle   = "Bảo trì hoàn tất — Sinh viên trả về phòng {$oldRoomCode}";
            $adminContent = "Sinh viên {$student->full_name} ({$student->student_code}) đã được chuyển về "
                          . "Giường {$oldBed->bed_number} (Phòng {$oldRoomCode}) sau khi bảo trì hoàn tất.";
            $this->createAdminNotif($adminTitle, $adminContent, 'room_maintenance_return', $log->maintenance_request_id ?? null);
            $pendingEmails[] = $this->buildAdminEmailPayload($adminTitle, $adminContent);
        }
    }

    private function createAdminNotif(string $title, string $content, string $type, mixed $relatedId = null): void
    {
        try {
            AdminNotification::create([
                'title'      => $title,
                'content'    => $content,
                'type'       => $type,
                'related_id' => $relatedId,
                'created_at' => now(),
            ]);
        } catch (\Exception) {}
    }

    private function buildAdminEmailPayload(string $title, string $content): array
    {
        $adminEmails = Account::where('role', 'admin')
            ->with('student')
            ->get()
            ->map(fn ($acc) => $acc->student?->email)
            ->push(config('auth.admin_login_email'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'admin_emails' => $adminEmails,
            'subject'      => 'KTX — ' . $title,
            'body'         => "<div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152'>
                <h2 style='color:#244cb8;margin-top:0'>" . htmlspecialchars($title) . "</h2>
                <p>" . nl2br(htmlspecialchars($content)) . "</p>
                <p style='color:#6b7280;font-size:12px;margin-top:32px'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
            </div>",
        ];
    }

    private function createNotifRecord(int $studentId, string $title, string $content, string $type): void
    {
        try {
            $notification = Notification::create([
                'student_id'  => $studentId,
                'title'       => $title,
                'content'     => $content,
                'type'        => $type,
                'target_type' => 'individual',
                'send_email'  => true,
            ]);
            DB::table('notification_recipient')->insert([
                'notification_id' => $notification->id,
                'student_id'      => $studentId,
                'is_read'         => false,
                'read_at'         => null,
            ]);
        } catch (\Exception) {}
    }

    private function buildEmailPayload(object $student, string $title, string $content): array
    {
        $name    = htmlspecialchars($student->full_name ?? '');
        $eTitle  = htmlspecialchars($title);
        $eBody   = nl2br(htmlspecialchars($content));
        $body    = "<div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1f3152'>
            <h2 style='color:#244cb8;margin-top:0'>{$eTitle}</h2>
            <p>Xin chào <strong>{$name}</strong>,</p>
            <p>{$eBody}</p>
            <p style='color:#6b7280;font-size:12px;margin-top:32px'>Email này được gửi tự động bởi hệ thống quản lý KTX.</p>
        </div>";

        return [
            'email'   => $student->email ?? '',
            'name'    => $student->full_name ?? '',
            'subject' => 'KTX — ' . $title,
            'body'    => $body,
        ];
    }

    /**
     * @param bool $queue Đưa vào hàng đợi thay vì gửi ngay — bật khi gọi trong vòng lặp
     *                    nhiều người nhận cùng lúc (cả phòng), tránh chặn request.
     */
    private function sendNotificationEmail(array $data, bool $queue = false): void
    {
        // Admin bulk email payload
        if (isset($data['admin_emails'])) {
            foreach ($data['admin_emails'] as $email) {
                try {
                    if ($queue) {
                        Mail::to($email)->queue(new GenericNotificationMail($data['subject'], $data['body']));
                    } else {
                        Mail::send([], [], function ($message) use ($email, $data) {
                            $message->to($email)->subject($data['subject'])->html($data['body']);
                        });
                    }
                } catch (\Exception $e) {
                    Log::error('Gửi email thông báo bảo trì (admin) thất bại', [
                        'email'   => $email,
                        'subject' => $data['subject'] ?? null,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
            return;
        }

        if (empty($data['email'])) {
            return;
        }
        try {
            if ($queue) {
                Mail::to($data['email'], $data['name'])->queue(new GenericNotificationMail($data['subject'], $data['body']));
            } else {
                Mail::send([], [], function ($message) use ($data) {
                    $message->to($data['email'], $data['name'])
                        ->subject($data['subject'])
                        ->html($data['body']);
                });
            }
        } catch (\Exception $e) {
            Log::error('Gửi email thông báo bảo trì thất bại', [
                'email'   => $data['email'],
                'subject' => $data['subject'] ?? null,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function insertRoomChangeLog(
        Occupancy $occupancy,
        int $oldRoomId,
        int $oldBedId,
        int $newRoomId,
        int $newBedId,
        string $reason,
        string $changeType,
        bool $temporary,
        ?int $maintenanceRequestId,
        ?string $status,
        ?string $expectedReturnDate = null,
        mixed $transferredAt = null,
    ): void
    {
        DB::table('room_change_log')->insert([
            'occupancy_id' => $occupancy->id,
            'old_room_id' => $oldRoomId,
            'old_bed_id' => $oldBedId,
            'new_room_id' => $newRoomId,
            'new_bed_id' => $newBedId,
            'transfer_reason' => $reason,
            'change_type' => $changeType,
            'maintenance_request_id' => $maintenanceRequestId,
            'status' => $status,
            'change_source' => 'admin',
            'is_temporary' => $temporary,
            'expected_return_date' => $expectedReturnDate,
            'transferred_at' => $transferredAt ?? now(),
        ]);
    }

    private function syncRoomBedStatuses(Room $room): void
    {
        $room->load('beds');
        foreach ($room->beds as $bed) {
            $bed->status = 'active';
            $bed->save();
        }
    }

    private function formatSource(Room $room, Bed $bed, ?Occupancy $occupancy): array
    {
        return [
            'room' => $this->formatRoomHeader($room),
            'bed' => [
                'id' => $bed->id,
                'bed_number' => (string) $bed->bed_number,
                'position' => strtolower((string) $bed->position) === 'lower' ? 'bottom' : 'top',
                'status' => strtolower((string) $bed->status) === 'maintenance' ? 'maintenance' : 'active',
            ],
            'occupancy_id' => $occupancy?->id,
            'student' => $occupancy ? $this->formatStudent($occupancy) : null,
        ];
    }

    private function formatStudent(Occupancy $occupancy): ?array
    {
        $student = $occupancy->student;
        if (!$student) {
            return null;
        }

        // Sinh viên nguồn giữ chỗ tân sinh viên có thể chưa có Student.avatar (dữ liệu cũ
        // trước fix) — fallback sang avatar_url của Registration gắn với occupancy này.
        $avatarRaw = $student->avatar ?? $occupancy->registration?->avatar_url;

        return [
            'id' => $student->id,
            'student_code' => $student->student_code,
            'full_name' => $student->full_name,
            'avatar' => $this->assetUrl($avatarRaw),
        ];
    }

    private function formatBedOption(Bed $bed, Room $sourceRoom): array
    {
        $bed->loadMissing('room.floor');
        return [
            'id' => $bed->id,
            'bed_number' => (string) $bed->bed_number,
            'room_id' => $bed->room_id,
            'room_code' => $this->roomCode($bed->room),
            'floor_number' => (int) $bed->room?->floor?->floor_number,
            'building_code' => (string) $bed->room?->floor?->building_code,
            'position' => strtolower((string) $bed->position) === 'lower' ? 'bottom' : 'top',
            'priority' => $this->bedPriority($bed, $sourceRoom),
        ];
    }

    private function formatRoomHeader(Room $room): array
    {
        $room->loadMissing('floor');
        return [
            'id' => $room->id,
            'room_number' => (string) $room->room_number,
            'room_code' => $this->roomCode($room),
            'building_code' => (string) $room->floor?->building_code,
            'floor_number' => (int) $room->floor?->floor_number,
            'gender' => (string) $room->floor?->gender,
            'status' => strtoupper((string) $room->status ?: 'ACTIVE'),
        ];
    }

    private function formatRoom(Room $room): array
    {
        $room->loadMissing(['floor', 'beds']);
        $roomInMaintenance = strtoupper((string) $room->status) === 'MAINTENANCE';
        $occupiedBedIds = $this->activeOccupancyQuery()
            ->whereIn('bed_id', $room->beds->pluck('id')->all())
            ->pluck('bed_id')
            ->mapWithKeys(fn ($bedId) => [(int) $bedId => true])
            ->all();

        $beds = $room->beds->sortBy('bed_number')->values()->map(function (Bed $bed) use ($occupiedBedIds) {
            return [
                'id' => $bed->id,
                'room_id' => $bed->room_id,
                'bed_number' => (string) $bed->bed_number,
                'position' => strtoupper((string) $bed->position ?: 'UPPER'),
                'status' => strtoupper((string) $bed->status) === 'MAINTENANCE' ? 'MAINTENANCE' : 'ACTIVE',
                'occupied' => isset($occupiedBedIds[(int) $bed->id]),
            ];
        });

        if ($roomInMaintenance) {
            $occupiedBeds = 0;
            $availableBeds = 0;
            $maintenanceBeds = (int) $room->capacity;
            $activeBeds = 0;
        } else {
            $occupiedBeds = $beds->filter(fn (array $bed) => $bed['occupied'])->count();
            $maintenanceBeds = $beds->filter(fn (array $bed) => $bed['status'] === 'MAINTENANCE')->count();
            $activeBeds = max((int) $room->capacity - $maintenanceBeds, 0);
            $availableBeds = max($activeBeds - $occupiedBeds, 0);
        }
        $displayStatus = $roomInMaintenance
            ? 'MAINTENANCE'
            : ($activeBeds > 0 && $availableBeds === 0 ? 'FULL' : 'AVAILABLE');

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
            'beds' => $beds->all(),
            'occupied_beds' => $occupiedBeds,
            'available_beds' => $availableBeds,
            'maintenance_beds' => $maintenanceBeds,
        ];
    }

    private function roomCode(?Room $room): string
    {
        if (!$room) {
            return '';
        }
        $room->loadMissing('floor');
        return (string) $room->floor?->building_code . (string) $room->room_number;
    }

    private function assetUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // Một số bản ghi (vd. Registration convert từ hồ sơ giữ chỗ tân sinh viên) đã có sẵn
        // prefix storage trong path lưu — phải strip trước, tránh double-prefix khiến ảnh 404.
        $cleanPath = preg_replace('#^/?(api/)?storage/#', '', ltrim($path, '/'));

        // Production/Railway lưu file vào volume, chỉ phục vụ được qua route riêng
        // /api/storage/{path} (StorageController::serveImage) — route /storage/{path} tĩnh
        // (qua symlink storage:link) chỉ có ở local dev, không tồn tại/không có file trên
        // Railway. Thiếu bước phân nhánh này khiến ảnh luôn vỡ trên host dù local vẫn đúng
        // (báo cáo 28/07 — cùng lớp lỗi với RegistrationController::getImageUrl()).
        $isProduction = app()->environment('production') || env('RAILWAY_ENVIRONMENT') === 'production';

        if ($isProduction) {
            return url('/api/storage/' . $cleanPath);
        }

        return url('/storage/' . $cleanPath);
    }
}
