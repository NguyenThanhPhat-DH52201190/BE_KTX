<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bed;
use App\Models\MaintenanceRequest;
use App\Models\Occupancy;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        $updatedRoom = DB::transaction(function () use ($roomId, $bedId, $data) {
            $room = Room::query()->with(['floor'])->lockForUpdate()->findOrFail($roomId);
            $sourceBed = Bed::query()->where('room_id', $room->id)->where('id', $bedId)->lockForUpdate()->first();

            if (!$sourceBed) {
                abort(response()->json(['message' => 'Không tìm thấy giường nguồn.'], 404));
            }

            $targetBed = Bed::query()->where('id', (int) $data['target_bed_id'])->lockForUpdate()->firstOrFail();
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

            return $room->fresh(['floor', 'beds']);
        });

        return response()->json($this->formatRoom($updatedRoom));
    }

    public function completeBedMaintenance(int $roomId, int $bedId): JsonResponse
    {
        $updatedRoom = DB::transaction(function () use ($roomId, $bedId) {
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

            return $room->fresh(['floor', 'beds']);
        });

        return response()->json($this->formatRoom($updatedRoom));
    }

    public function roomPlan(int $roomId): JsonResponse
    {
        $room = Room::query()->with(['floor', 'beds'])->findOrFail($roomId);
        $occupancies = $this->activeOccupancyQuery()
            ->where('room_id', $room->id)
            ->with(['student', 'bed', 'room.floor'])
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

        $updatedRoom = DB::transaction(function () use ($roomId, $data) {
            $room = Room::query()->with(['floor', 'beds'])->lockForUpdate()->findOrFail($roomId);
            $targets = collect($data['assignments'])->pluck('target_bed_id')->map(fn ($id) => (int) $id);

            if ($targets->unique()->count() !== $targets->count()) {
                abort(response()->json(['message' => 'Mỗi sinh viên phải có một giường đích khác nhau.'], 422));
            }

            $targetBeds = Bed::query()->whereIn('id', $targets->all())->lockForUpdate()->get()->keyBy('id');
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

            $maintenanceRequest = MaintenanceRequest::create([
                'type' => 'ROOM',
                'room_id' => $room->id,
                'bed_id' => null,
                'reason' => trim($data['reason']),
                'status' => 'IN_PROGRESS',
                'started_at' => $data['started_at'],
                'expected_end_at' => $data['expected_end_at'],
            ]);

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
            }

            $room->status = 'maintenance';
            $room->save();
            $room->beds()->update(['status' => 'maintenance']);

            return $room->fresh(['floor', 'beds']);
        });

        return response()->json($this->formatRoom($updatedRoom));
    }

    public function completeRoomMaintenance(int $roomId): JsonResponse
    {
        $updatedRoom = DB::transaction(function () use ($roomId) {
            $room = Room::query()->with(['floor', 'beds'])->lockForUpdate()->findOrFail($roomId);
            $logs = DB::table('room_change_log')
                ->where('old_room_id', $room->id)
                ->where('change_type', 'TEMPORARY_MAINTENANCE')
                ->where('transfer_reason', 'ROOM_MAINTENANCE')
                ->where('status', 'ACTIVE')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($logs->isEmpty() && false) {
                abort(response()->json(['message' => 'Không tìm thấy lịch sử chuyển tạm của phòng này.'], 422));
            }

            foreach ($logs as $log) {
                $this->returnRoomMaintenanceLog($log);
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

        return response()->json($this->formatRoom($updatedRoom));
    }

    public function completeRoomMaintenanceStudent(int $roomId, int $occupancyId): JsonResponse
    {
        $updatedRoom = DB::transaction(function () use ($roomId, $occupancyId) {
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

            $this->returnRoomMaintenanceLog($log);

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

    private function returnRoomMaintenanceLog(object $log): void
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

        return [
            'id' => $student->id,
            'student_code' => $student->student_code,
            'full_name' => $student->full_name,
            'avatar' => $this->assetUrl($student->avatar),
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

        return url('/storage/' . ltrim($path, '/'));
    }
}
