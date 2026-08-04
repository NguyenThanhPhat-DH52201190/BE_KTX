<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bed;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Occupancy;
use App\Models\Room;
use App\Services\ExcelService;
use App\Services\PdfService;
use App\Support\VnFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BuildingController extends Controller
{
    private function normalizeStatus(?string $status, string $default = 'active'): string
    {
        $value = strtolower(trim((string) ($status ?? $default)));

        return in_array($value, ['active', 'maintenance', 'inactive'], true) ? $value : $default;
    }

    private function normalizeGender(?string $gender): ?string
    {
        if ($gender === null) {
            return null;
        }

        $value = strtolower(trim($gender));

        return in_array($value, ['male', 'female'], true) ? $value : null;
    }

    private function getOccupiedStudentsForBuilding(string $buildingCode): int
    {
        return Occupancy::occupiedBedsQuery()
            ->whereHas('room.floor', function ($query) use ($buildingCode) {
                $query->where('building_code', $buildingCode);
            })
            ->count();
    }

    private function formatFloor(Floor $floor): array
    {
        $floor->loadMissing('rooms.beds');

        $bedIds = $floor->rooms
            ->flatMap(fn ($room) => $room->beds->pluck('id'))
            ->filter()
            ->values()
            ->all();
        $occupiedStudents = empty($bedIds)
            ? 0
            : Occupancy::occupiedBedsQuery()->whereIn('bed_id', $bedIds)->count();

        return [
            'id' => $floor->id,
            'floorNumber' => $floor->floor_number,
            'gender' => strtoupper((string) ($floor->gender ?? 'MALE')),
            'roomCount' => $floor->rooms->count(),
            'bedCount' => $floor->rooms->sum(fn ($room) => $room->beds->count()),
            'occupiedStudents' => $occupiedStudents,
            'status' => strtoupper((string) ($floor->status ?? 'ACTIVE')),
        ];
    }

    private function formatBuilding(Building $building): array
    {
        $building->loadMissing(['floors.rooms.beds']);

        return [
            'id' => $building->building_code,
            'building_code' => $building->building_code,
            'name' => $building->name,
            'address' => $building->address,
            'status' => strtoupper((string) $building->status),
            'floors' => $building->floors
                ->sortBy('floor_number')
                ->values()
                ->map(fn (Floor $floor) => $this->formatFloor($floor))
                ->all(),
        ];
    }

    private function findBuildingOrFail(string $buildingCode): Building
    {
        return Building::query()->where('building_code', $buildingCode)->firstOrFail();
    }

    public function index()
    {
        $buildings = Building::query()
            ->with(['floors.rooms.beds'])
            ->orderBy('building_code')
            ->get();

        return response()->json($buildings->map(fn (Building $building) => $this->formatBuilding($building))->values()->all());
    }

    public function show(string $buildingCode)
    {
        return response()->json($this->formatBuilding($this->findBuildingOrFail($buildingCode)));
    }

    private function buildDetailExportPayload(string $buildingCode): array
    {
        $building = $this->findBuildingOrFail($buildingCode);
        $building->loadMissing(['floors.rooms.beds']);

        $bedIds = $building->floors
            ->flatMap(fn (Floor $floor) => $floor->rooms)
            ->flatMap(fn (Room $room) => $room->beds->pluck('id'))
            ->filter()
            ->values()
            ->all();

        $occupiedBedIds = empty($bedIds)
            ? []
            : Occupancy::occupiedBedsQuery()->whereIn('bed_id', $bedIds)->pluck('bed_id')->all();

        $floors = $building->floors->sortBy('floor_number')->values()->map(function (Floor $floor) use ($occupiedBedIds) {
            return [
                'floor_number' => $floor->floor_number,
                'gender' => strtoupper((string) ($floor->gender ?? 'MALE')),
                'status' => strtoupper((string) ($floor->status ?? 'ACTIVE')),
                'rooms' => $floor->rooms->sortBy('room_number')->values()->map(function (Room $room) use ($occupiedBedIds) {
                    $bedCount = $room->beds->count();
                    $occupiedCount = $room->beds->filter(fn (Bed $bed) => in_array($bed->id, $occupiedBedIds, true))->count();

                    return [
                        'room_number' => $room->room_number,
                        'capacity' => $room->capacity,
                        'price_per_month' => $room->price_per_month,
                        'status' => strtoupper((string) $room->status),
                        'bed_count' => $bedCount,
                        'occupied_count' => $occupiedCount,
                    ];
                })->all(),
            ];
        })->all();

        return [
            'building' => [
                'code' => $building->building_code,
                'name' => $building->name,
                'address' => $building->address,
            ],
            'floors' => $floors,
        ];
    }

    public function exportDetailPdf(string $buildingCode, PdfService $pdfService): Response
    {
        $payload = $this->buildDetailExportPayload($buildingCode);

        return $pdfService->download(
            'pdf.building-detail',
            $payload,
            'danh_sach_phong_giuong_' . $buildingCode . '.pdf',
        );
    }

    public function exportDetailExcel(string $buildingCode, ExcelService $excelService): Response
    {
        $payload = $this->buildDetailExportPayload($buildingCode);
        $roomStatusLabels = ['ACTIVE' => 'Đang sử dụng', 'MAINTENANCE' => 'Bảo trì', 'INACTIVE' => 'Ngừng sử dụng'];
        $genderLabels = ['MALE' => 'Nam', 'FEMALE' => 'Nữ'];

        $rows = [];
        $stt = 1;
        foreach ($payload['floors'] as $floor) {
            foreach ($floor['rooms'] as $room) {
                $rows[] = [
                    $stt++,
                    $floor['floor_number'],
                    $genderLabels[$floor['gender']] ?? $floor['gender'],
                    $room['room_number'],
                    $room['capacity'],
                    VnFormat::currency($room['price_per_month']),
                    $roomStatusLabels[$room['status']] ?? $room['status'],
                    $room['occupied_count'] . '/' . $room['bed_count'],
                ];
            }
        }

        return $excelService->download(
            'danh_sach_phong_giuong_' . $buildingCode . '.xlsx',
            [[
                'title' => 'Toa ' . $buildingCode,
                'headers' => ['STT', 'Tầng', 'Giới tính', 'Số phòng', 'Sức chứa', 'Giá/tháng', 'Trạng thái', 'Giường đã ở/tổng'],
                'rows' => $rows,
            ]],
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'building_code' => ['required', 'string', 'max:10', 'unique:buildings,building_code'],
            'address' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $buildingCode = strtoupper(trim($data['building_code']));
        $building = Building::create([
            'building_code' => $buildingCode,
            'name' => trim((string) ($data['name'] ?? ('Tòa ' . $buildingCode))),
            'address' => trim($data['address']),
            'status' => $this->normalizeStatus($data['status']),
        ]);

        return response()->json($this->formatBuilding($building), 201);
    }

    public function update(Request $request, string $buildingCode)
    {
        $building = $this->findBuildingOrFail($buildingCode);

        $data = $request->validate([
            'building_code' => ['required', 'string', 'max:10'],
            'address' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $nextCode = strtoupper(trim($data['building_code']));
        $nextAddress = trim($data['address']);
        $nextStatus = $this->normalizeStatus($data['status']);
        $nextName = trim((string) ($data['name'] ?? ('Tòa ' . $nextCode)));

        if ($nextCode !== $building->building_code && Building::query()->where('building_code', $nextCode)->exists()) {
            return response()->json(['message' => 'Tòa đã tồn tại.'], 422);
        }

        $occupiedStudents = $this->getOccupiedStudentsForBuilding($building->building_code);
        if ($occupiedStudents > 0 && in_array($nextStatus, ['maintenance', 'inactive'], true) && $building->status !== 'maintenance') {
            return response()->json(['message' => 'Tòa đang có sinh viên nên không thể chuyển trạng thái này.'], 422);
        }

        DB::beginTransaction();

        try {
            $originalCode = $building->building_code;

            if ($nextCode !== $originalCode) {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                $building->building_code = $nextCode;
                $building->name = $nextName;
                $building->address = $nextAddress;
                $building->status = $nextStatus;
                $building->save();

                Floor::query()->where('building_code', $originalCode)->update(['building_code' => $nextCode]);
            } else {
                $building->update([
                    'name' => $nextName,
                    'address' => $nextAddress,
                    'status' => $nextStatus,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return response()->json($this->formatBuilding($this->findBuildingOrFail($nextCode)));
    }

    public function destroy(string $buildingCode)
    {
        $building = $this->findBuildingOrFail($buildingCode);

        if ($this->getOccupiedStudentsForBuilding($building->building_code) > 0) {
            return response()->json(['message' => 'Không thể xóa tòa khi vẫn còn sinh viên đang ở.'], 422);
        }

        $building->delete();

        return response()->noContent();
    }

    public function storeFloor(Request $request, string $buildingCode)
    {
        $building = $this->findBuildingOrFail($buildingCode);

        $data = $request->validate([
            'floor_number' => ['required', 'integer', 'min:1'],
            'gender' => ['required', 'string'],
            'status' => ['required', 'string'],
        ]);

        if (Floor::query()->where('building_code', $building->building_code)->where('floor_number', $data['floor_number'])->exists()) {
            return response()->json(['message' => 'Tầng đã tồn tại.'], 422);
        }

        $floor = Floor::create([
            'building_code' => $building->building_code,
            'floor_number' => $data['floor_number'],
            'gender' => $this->normalizeGender($data['gender']),
            'status' => $this->normalizeStatus($data['status']),
        ]);

        return response()->json($this->formatFloor($floor), 201);
    }

    public function updateFloor(Request $request, string $buildingCode, int $floorNumber)
    {
        $building = $this->findBuildingOrFail($buildingCode);
        $floor = Floor::query()->where('building_code', $building->building_code)->where('floor_number', $floorNumber)->firstOrFail();

        $data = $request->validate([
            'floor_number' => ['required', 'integer', 'min:1'],
            'gender' => ['required', 'string'],
            'status' => ['required', 'string'],
        ]);

        $nextFloorNumber = (int) $data['floor_number'];
        if ($nextFloorNumber !== $floor->floor_number) {
            $conflict = Floor::query()
                ->where('building_code', $building->building_code)
                ->where('floor_number', $nextFloorNumber)
                ->exists();

            if ($conflict) {
                return response()->json(['message' => 'Tầng đã tồn tại.'], 422);
            }
        }

        $floor->update([
            'floor_number' => $nextFloorNumber,
            'gender' => $this->normalizeGender($data['gender']),
            'status' => $this->normalizeStatus($data['status']),
        ]);

        return response()->json($this->formatFloor($floor));
    }

    public function destroyFloor(string $buildingCode, int $floorNumber)
    {
        $building = $this->findBuildingOrFail($buildingCode);
        $floor = Floor::query()->where('building_code', $building->building_code)->where('floor_number', $floorNumber)->firstOrFail();

        $occupiedStudents = Occupancy::occupiedBedsQuery()
            ->whereHas('room', function ($query) use ($floor) {
                $query->where('floor_id', $floor->id);
            })
            ->count();

        if ($occupiedStudents > 0) {
            return response()->json(['message' => 'Không thể xóa tầng vì đang có sinh viên ở.'], 422);
        }

        $floor->delete();

        return response()->noContent();
    }

    // Thống kê công khai cho trang chủ (chưa đăng nhập) — số tòa, số phòng & tổng giường thực tế.
    public function publicStats()
    {
        return response()->json([
            'buildings' => Building::query()->count(),
            'rooms' => Room::query()->count(),
            'beds' => Bed::query()->count(),
        ]);
    }
}
