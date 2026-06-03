<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bed;
use App\Models\Floor;
use App\Models\Occupancy;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    public function index(): JsonResponse
    {
        $rooms = Room::query()->with(['floor', 'beds'])->orderBy('id')->get();
        $occupiedBedIds = $this->getOccupiedBedIdSet($rooms);

        return response()->json(
            $rooms->map(fn (Room $room) => $this->formatRoom($room, $occupiedBedIds))->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'floor_id' => ['required', 'integer', 'exists:floors,id'],
            'room_number' => ['required', 'string', 'max:10'],
            'capacity' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string'],
            'price_per_quarter' => ['nullable', 'numeric'],
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
                'price_per_quarter' => (float) ($data['price_per_quarter'] ?? 0),
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
            'price_per_quarter' => ['nullable', 'numeric'],
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

        $room = DB::transaction(function () use ($room, $data, $roomNumber) {
            $room->update([
                'floor_id' => (int) $data['floor_id'],
                'room_number' => $roomNumber,
                'capacity' => (int) $data['capacity'],
                'price_per_quarter' => (float) ($data['price_per_quarter'] ?? $room->price_per_quarter ?? 0),
                'status' => $this->normalizeRoomStatusInput($data['status'] ?? $room->status),
            ]);

            $this->syncBeds($room, (int) $data['capacity'], $data['maintenance_beds'] ?? []);

            return $room->fresh(['floor', 'beds']);
        });

        return response()->json($this->formatRoom($room, $this->getOccupiedBedIdSet(collect([$room]))));
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

    public function updateBed(Request $request, int $roomId, int $bedId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:empty,maintenance'],
        ]);

        $room = Room::query()->with(['floor', 'beds'])->findOrFail($roomId);
        $bed = $room->beds->firstWhere('id', $bedId);

        if (!$bed) {
            return response()->json(['message' => 'Không tìm thấy giường.'], 404);
        }

        if ($this->isBedOccupied($bedId) && $data['status'] === 'maintenance') {
            return response()->json(['message' => 'Giường đang có sinh viên ở nên không thể chuyển sang bảo trì.'], 422);
        }

        $bed->update(['status' => $data['status']]);

        $room = $room->fresh(['floor', 'beds']);

        return response()->json($this->formatRoom($room, $this->getOccupiedBedIdSet(collect([$room]))));
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
                'status' => in_array($index + 1, $maintenanceBeds, true) ? 'maintenance' : $bed->status,
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
                'status' => in_array($bedNumber, $maintenanceBeds, true) ? 'maintenance' : 'empty',
            ]);
        }
    }

    private function countOccupiedBedsForRoom(Room $room): int
    {
        return Occupancy::query()
            ->where('room_id', $room->id)
            ->whereIn(DB::raw('UPPER(status)'), ['ACTIVE', 'OCCUPIED'])
            ->count();
    }

    private function isBedOccupied(int $bedId): bool
    {
        return Occupancy::query()
            ->where('bed_id', $bedId)
            ->whereIn(DB::raw('UPPER(status)'), ['ACTIVE', 'OCCUPIED'])
            ->exists();
    }

    private function getOccupiedBedIdSet($rooms): array
    {
        $bedIds = $rooms->flatMap(fn (Room $room) => $room->beds->pluck('id'))->unique()->values();

        if ($bedIds->isEmpty()) {
            return [];
        }

        return Occupancy::query()
            ->whereIn('bed_id', $bedIds->all())
            ->whereIn(DB::raw('UPPER(status)'), ['ACTIVE', 'OCCUPIED'])
            ->pluck('bed_id')
            ->mapWithKeys(fn ($bedId) => [(int) $bedId => true])
            ->all();
    }

    private function normalizeRoomStatusInput(string $status): string
    {
        $value = strtolower(trim($status));

        return $value === 'maintenance' ? 'maintenance' : 'active';
    }

    private function formatRoom(Room $room, array $occupiedBedIds): array
    {
        $beds = $room->beds->sortBy('bed_number')->values();
        $bedPayload = $beds->map(function (Bed $bed) use ($occupiedBedIds) {
            $occupied = isset($occupiedBedIds[(int) $bed->id]);

            return [
                'id' => $bed->id,
                'room_id' => $bed->room_id,
                'bed_number' => (string) $bed->bed_number,
                'position' => strtoupper((string) $bed->position ?: 'UPPER'),
                'status' => strtoupper((string) $bed->status ?: 'EMPTY'),
                'occupied' => $occupied,
            ];
        });

        $occupiedBeds = $bedPayload->filter(fn (array $bed) => $bed['occupied'])->count();
        $maintenanceBeds = $bedPayload->filter(fn (array $bed) => $bed['status'] === 'MAINTENANCE')->count();
        $activeBeds = max($room->capacity - $maintenanceBeds, 0);
        $availableBeds = max($activeBeds - $occupiedBeds, 0);

        $displayStatus = strtoupper((string) $room->status) === 'MAINTENANCE'
            ? 'MAINTENANCE'
            : ($availableBeds === 0 && $activeBeds > 0 ? 'FULL' : 'AVAILABLE');

        return [
            'id' => $room->id,
            'floor_id' => $room->floor_id,
            'building_code' => $room->floor?->building_code ?? '',
            'room_number' => (string) $room->room_number,
            'floor_number' => (int) ($room->floor?->floor_number ?? 0),
            'capacity' => (int) $room->capacity,
            'price_per_quarter' => (float) $room->price_per_quarter,
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
        ];
    }
}
